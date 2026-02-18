<?php

namespace App\Services;

use App\Models\GoogleOAuthToken;
use Google\Client;
use Google\Service\Docs;
use Google\Service\Docs\BatchUpdateDocumentRequest;
use Google\Service\Docs\ReplaceAllTextRequest;
use Google\Service\Docs\Request as DocsRequest;
use Google\Service\Docs\SubstringMatchCriteria;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Google\Service\Drive\Permission;
use Illuminate\Support\Facades\Log;

class GoogleDriveService
{
    protected ?Client $client = null;
    protected ?Drive $driveService = null;
    protected ?Docs $docsService = null;
    protected bool $configured = false;

    public function __construct()
    {
        try {
            $clientId = config("google.client_id");
            $clientSecret = config("google.client_secret");

            if (!$clientId || !$clientSecret) {
                Log::warning("Google OAuth2 credentials not configured");
                return;
            }

            $this->client = new Client();
            $this->client->setClientId($clientId);
            $this->client->setClientSecret($clientSecret);
            $this->client->setRedirectUri(config("google.redirect_uri"));

            $this->client->addScope([Drive::DRIVE, Docs::DOCUMENTS]);

            // IMPORTANT: offline access for refresh tokens
            $this->client->setAccessType("offline");
            $this->client->setPrompt("consent");

            $this->loadStoredToken();

            $this->driveService = new Drive($this->client);
            $this->docsService = new Docs($this->client);
            $this->configured = true;
        } catch (\Exception $e) {
            Log::error("Failed to initialize Google Drive service", [
                "error" => $e->getMessage(),
            ]);
        }
    }

    /**
     * Load stored OAuth token from database.
     */
    protected function loadStoredToken(): bool
    {
        $token = GoogleOAuthToken::getActive();

        if (!$token) {
            return false;
        }

        $accessToken = [
            "access_token" => $token->access_token,
            "refresh_token" => $token->refresh_token,
            "expires_in" => $token->expires_at?->diffInSeconds(now()) ?? 0,
            "created" => now()
                ->subSeconds($token->expires_at?->diffInSeconds(now()) ?? 0)
                ->getTimestamp(),
        ];

        $this->client->setAccessToken($accessToken);

        // Refresh token if expired or about to expire (5 minute buffer)
        if ($token->willExpireWithin(5)) {
            return $this->refreshToken($token);
        }

        return true;
    }

    /**
     * Refresh the access token using the refresh token.
     */
    protected function refreshToken(GoogleOAuthToken $token): bool
    {
        try {
            if (!$token->refresh_token) {
                Log::error("No refresh token available for Google OAuth");
                return false;
            }

            $this->client->fetchAccessTokenWithRefreshToken(
                $token->refresh_token,
            );
            $newAccessToken = $this->client->getAccessToken();

            // Update the token in database
            $token->update([
                "access_token" => $newAccessToken["access_token"],
                "expires_at" => now()->addSeconds(
                    $newAccessToken["expires_in"],
                ),
            ]);

            Log::info("Google OAuth token refreshed successfully");

            return true;
        } catch (\Exception $e) {
            Log::error("Failed to refresh Google OAuth token", [
                "error" => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Handle OAuth callback and store the token.
     */
    public function handleCallback(string $authCode): bool
    {
        try {
            $accessToken = $this->client->fetchAccessTokenWithAuthCode(
                $authCode,
            );

            if (isset($accessToken["error"])) {
                Log::error("Google OAuth error", [
                    "error" => $accessToken["error"],
                ]);
                return false;
            }

            // Delete existing tokens (single system-wide token)
            GoogleOAuthToken::query()->delete();

            // Store the new token
            GoogleOAuthToken::create([
                "user_id" => auth()->id(),
                "access_token" => $accessToken["access_token"],
                "refresh_token" => $accessToken["refresh_token"] ?? null,
                "expires_at" => now()->addSeconds($accessToken["expires_in"]),
                "scopes" => $accessToken["scope"]
                    ? explode(" ", $accessToken["scope"])
                    : null,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error("Failed to handle Google OAuth callback", [
                "error" => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Create a new folder in Google Drive.
     */
    public function createFolder(string $name, ?string $parentId = null): string
    {
        $this->ensureConfigured();

        $fileMetadata = new DriveFile([
            "name" => $name,
            "mimeType" => "application/vnd.google-apps.folder",
        ]);

        if ($parentId) {
            $fileMetadata->setParents([$parentId]);
        }

        $folder = $this->driveService->files->create($fileMetadata, [
            "fields" => "id",
            "supportsAllDrives" => true,
        ]);

        return $folder->id;
    }

    /**
     * Replace text placeholders in a Google Document.
     *
     * @param  array<string, string>  $replacements
     */
    public function replaceTextInDocument(
        string $documentId,
        array $replacements,
    ): void {
        $this->ensureConfigured();

        // Check if the document has tabs (which are not supported)
        if ($this->documentHasTabs($documentId)) {
            throw new \RuntimeException(
                "This document uses Google Docs tabs which are not supported for text replacement.",
            );
        }

        $requests = [];

        foreach ($replacements as $placeholder => $value) {
            $replaceRequest = new ReplaceAllTextRequest([
                "containsText" => new SubstringMatchCriteria([
                    "text" => $placeholder,
                    "matchCase" => true,
                ]),
                "replaceText" => $value ?? "",
            ]);

            $requests[] = new DocsRequest([
                "replaceAllText" => $replaceRequest,
            ]);
        }

        if (!empty($requests)) {
            $batchUpdateRequest = new BatchUpdateDocumentRequest([
                "requests" => $requests,
            ]);

            $this->docsService->documents->batchUpdate(
                $documentId,
                $batchUpdateRequest,
            );
        }
    }

    /**
     * Export a Google Document to PDF and save it to Drive.
     */
    public function exportToPdf(
        string $documentId,
        string $folderId,
        string $name,
    ): string {
        $this->ensureConfigured();

        // Get the first tab ID to avoid "Tab 1" title page in PDF
        $tabId = $this->getFirstTabId($documentId);

        // Build export URL with optional tab parameter
        $exportUrl = "https://docs.google.com/document/d/{$documentId}/export?format=pdf";
        if ($tabId) {
            $exportUrl .= "&tab={$tabId}";
        }

        // Get access token for authentication
        $accessToken = $this->client->getAccessToken();
        $token = is_array($accessToken)
            ? $accessToken["access_token"] ?? null
            : null;

        // Download PDF content using direct URL
        $client = new \GuzzleHttp\Client();
        $response = $client->get($exportUrl, [
            "headers" => [
                "Authorization" => "Bearer " . $token,
            ],
        ]);

        $pdfContent = $response->getBody()->getContents();

        // Create a new file with the PDF content
        $fileMetadata = new DriveFile([
            "name" => $name,
            "parents" => [$folderId],
            "mimeType" => "application/pdf",
        ]);

        $pdfFile = $this->driveService->files->create($fileMetadata, [
            "data" => $pdfContent,
            "mimeType" => "application/pdf",
            "uploadType" => "multipart",
            "fields" => "id",
            "supportsAllDrives" => true,
        ]);

        return $pdfFile->id;
    }

    /**
     * Set file permissions to "anyone with link can view".
     */
    public function setPublicViewPermission(string $fileId): void
    {
        $this->ensureConfigured();

        $permission = new Permission([
            "type" => "anyone",
            "role" => "reader",
        ]);

        $this->driveService->permissions->create($fileId, $permission, [
            "supportsAllDrives" => true,
        ]);
    }

    /**
     * Throw exception if Google Drive is not configured.
     */
    protected function ensureConfigured(): void
    {
        if (!$this->configured) {
            throw new \RuntimeException(
                "Google Drive is not configured. Please add OAuth2 credentials.",
            );
        }

        if (!GoogleOAuthToken::getActive()) {
            throw new \RuntimeException("Google account is not connected.");
        }
    }
}
