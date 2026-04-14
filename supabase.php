<?php
/**
 * supabase.php — Lightweight Supabase REST + Auth wrapper
 *
 * Usage:
 *   require_once 'config.php';
 *   require_once 'supabase.php';
 *   $sb = new Supabase();
 *
 *   // Fetch rows
 *   $forms = $sb->from('forms')->select('*')->eq('is_active', 'true')->execute();
 *
 *   // Insert
 *   $sb->from('users')->insert(['email' => 'a@b.com', 'auth_id' => '…', 'display_name' => 'Ada']);
 *
 *   // Update
 *   $sb->from('users')->eq('id', $userId)->update(['role' => 'admin']);
 *
 *   // Auth helpers
 *   $loginUrl = Supabase::getGoogleOAuthURL();
 *   $session  = Supabase::exchangeCodeForSession($code);
 *   $user     = Supabase::getAuthUser($accessToken);
 */

class Supabase
{
    private string $baseUrl;
    private string $apiKey;       // Supabase Secret key (sb_secret_...) for server ops
    private string $table  = '';
    private string $query  = '';
    private array  $headers = [];

    public function __construct(?string $apiKey = null)
    {
        $this->baseUrl = rtrim(SUPABASE_URL, '/');
        $this->apiKey  = $apiKey ?? SUPABASE_SECRET_KEY;
        $this->headers = [
            'apikey: '        . $this->apiKey,
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'Prefer: return=representation',
        ];
    }

    // ── Query builder ───────────────────────────────────

    /**
     * Target a table.
     */
    public function from(string $table): self
    {
        $clone = clone $this;
        $clone->table = $table;
        $clone->query = '';
        return $clone;
    }

    /**
     * Select columns.
     */
    public function select(string $columns = '*'): self
    {
        $this->query .= '&select=' . urlencode($columns);
        return $this;
    }

    /**
     * Equality filter.
     */
    public function eq(string $column, string $value): self
    {
        $this->query .= '&' . urlencode($column) . '=eq.' . urlencode($value);
        return $this;
    }

    /**
     * IN filter.
     */
    public function in(string $column, array $values): self
    {
        $list = '(' . implode(',', array_map(fn($v) => '"' . $v . '"', $values)) . ')';
        $this->query .= '&' . urlencode($column) . '=in.' . $list;
        return $this;
    }

    /**
     * Order results.
     */
    public function order(string $column, bool $ascending = true): self
    {
        $dir = $ascending ? 'asc' : 'desc';
        $this->query .= '&order=' . urlencode($column) . '.' . $dir;
        return $this;
    }

    /**
     * Limit number of rows.
     */
    public function limit(int $count): self
    {
        $this->query .= '&limit=' . $count;
        return $this;
    }

    /**
     * Execute a GET request (select).
     * Returns decoded array or null on failure.
     */
    public function execute(): ?array
    {
        $url = $this->baseUrl . '/rest/v1/' . $this->table . '?' . ltrim($this->query, '&');
        return $this->request('GET', $url);
    }

    /**
     * Insert one or more rows. Pass a single assoc array or array of arrays.
     */
    public function insert(array $data): ?array
    {
        // If it's a single record (assoc array), wrap it
        if (array_keys($data) !== range(0, count($data) - 1)) {
            $data = [$data];
        }
        $url = $this->baseUrl . '/rest/v1/' . $this->table;
        return $this->request('POST', $url, $data);
    }

    /**
     * Update rows matching current filters.
     */
    public function update(array $data): ?array
    {
        $url = $this->baseUrl . '/rest/v1/' . $this->table . '?' . ltrim($this->query, '&');
        return $this->request('PATCH', $url, $data);
    }

    /**
     * Delete rows matching current filters.
     */
    public function delete(): ?array
    {
        $url = $this->baseUrl . '/rest/v1/' . $this->table . '?' . ltrim($this->query, '&');
        return $this->request('DELETE', $url);
    }

    // ── RPC (call a Postgres function) ──────────────────

    /**
     * Call a Supabase/Postgres RPC function.
     */
    public function rpc(string $functionName, array $params = []): ?array
    {
        $url = $this->baseUrl . '/rest/v1/rpc/' . $functionName;
        return $this->request('POST', $url, $params);
    }

    // ── Auth helpers (static) ───────────────────────────

    /**
     * Generate a PKCE code verifier (random 64-char string).
     */
    public static function generateCodeVerifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    }

    /**
     * Derive the S256 code challenge from a verifier.
     */
    public static function generateCodeChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /**
     * Build the Supabase OAuth redirect URL for Google sign-in (PKCE flow).
     * Stores the code_verifier in $_SESSION so auth-callback.php can use it.
     */
    public static function getGoogleOAuthURL(): string
    {
        $codeVerifier  = self::generateCodeVerifier();
        $codeChallenge = self::generateCodeChallenge($codeVerifier);

        // Store verifier in session — needed when exchanging the code
        $_SESSION['pkce_code_verifier'] = $codeVerifier;

        $redirectTo = APP_URL . '/auth-callback.php';

        return SUPABASE_URL . '/auth/v1/authorize?'
            . http_build_query([
                'provider'              => 'google',
                'redirect_to'           => $redirectTo,
                'flow_type'             => 'pkce',
                'code_challenge'        => $codeChallenge,
                'code_challenge_method' => 'S256',
            ]);
    }

    /**
     * Exchange an auth code for a Supabase session (PKCE flow).
     * Returns the full session JSON (access_token, refresh_token, user, etc.)
     */
    public static function exchangeCodeForSession(string $code, string $codeVerifier): ?array
    {
        $url = SUPABASE_URL . '/auth/v1/token?grant_type=pkce';
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode([
                'auth_code'     => $code,
                'code_verifier' => $codeVerifier,
            ]),
            CURLOPT_HTTPHEADER     => [
                'apikey: '       . SUPABASE_PUBLISHABLE_KEY,
                'Content-Type: application/json',
            ],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            return json_decode($response, true);
        }

        error_log("Supabase token exchange failed ($httpCode): $response");
        // TEMPORARY: return error body for debugging
        return json_decode($response, true);
    }

    /**
     * Fetch the authenticated user's profile from Supabase Auth.
     */
    public static function getAuthUser(string $accessToken): ?array
    {
        $url = SUPABASE_URL . '/auth/v1/user';
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'apikey: '        . SUPABASE_PUBLISHABLE_KEY,
                'Authorization: Bearer ' . $accessToken,
            ],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            return json_decode($response, true);
        }

        error_log("Supabase getUser failed ($httpCode): $response");
        return null;
    }

    // ── Internal HTTP ───────────────────────────────────

    private function request(string $method, string $url, ?array $body = null): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $this->headers,
            CURLOPT_CUSTOMREQUEST  => $method,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            error_log("Supabase cURL error: $curlErr");
            return null;
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            return json_decode($response, true) ?? [];
        }

        error_log("Supabase API error ($method $url) [$httpCode]: $response");
        return null;
    }
}
