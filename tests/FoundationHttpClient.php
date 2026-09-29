<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/** CLI regression HTTP client. Cookies and CSRF tokens stay in memory. */
final class FoundationHttpClient
{
    private $curl;
    public string $csrf = '';
    public array $headers = [];
    public function __construct(private string $base)
    {
        $this->curl = curl_init();
        curl_setopt_array($this->curl, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => '',
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'CSMS-Foundation-Regression',
        ]);
    }
    public function request(string $path, array $data = [], string $method = 'GET', ?string $token = null): array
    {
        $this->headers = [];
        $url = $this->base . '/' . $path;
        if ($method === 'GET' && $data) { $url .= '?' . http_build_query($data); }
        if ($method === 'POST' && $token !== '') { $data['_csrf_token'] = $token ?? $this->csrf; }
        curl_setopt_array($this->curl, [
            CURLOPT_URL => $url, CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $method === 'POST' ? http_build_query($data) : null,
            CURLOPT_HTTPHEADER => $method === 'POST' ? ['Content-Type: application/x-www-form-urlencoded'] : [],
            CURLOPT_HEADERFUNCTION => function ($curl, $line) { $this->headers[] = trim($line); return strlen($line); },
        ]);
        // Setting POSTFIELDS can change libcurl's method; set it last.
        curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, $method);
        $body = curl_exec($this->curl);
        if ($body === false) { throw new RuntimeException('HTTP transport failed.'); }
        $status = (int)curl_getinfo($this->curl, CURLINFO_RESPONSE_CODE);
        if (preg_match('/name="csrf-token" content="([^"]+)"/', $body, $match)
            || preg_match('/name="_csrf_token" value="([^"]+)"/', $body, $match)) {
            $this->csrf = html_entity_decode($match[1], ENT_QUOTES);
        }
        return ['status' => $status, 'body' => $body, 'json' => json_decode($body, true)];
    }
    public function api(string $endpoint, string $action, array $data = [], string $method = 'GET', ?string $token = null): array
    {
        return $this->request('modules/curriculum/http/' . $endpoint . '_actions.php', ['action' => $action] + $data, $method, $token);
    }
    public function close(): void { curl_close($this->curl); }
}
