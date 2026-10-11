<?php
declare(strict_types=1);
final class CandidateHttp {
    public static function auth(int $id, string $action): array {
        wp_set_current_user($id);
        $token = WP_Session_Tokens::get_instance($id)->create(time() + 600);
        $logged = wp_generate_auth_cookie($id, time() + 600, 'logged_in', $token);
        $_COOKIE[LOGGED_IN_COOKIE] = $logged;
        $nonce = wp_create_nonce($action);
        $cookie = LOGGED_IN_COOKIE . '=' . $logged . '; ' . AUTH_COOKIE . '=' . wp_generate_auth_cookie($id, time() + 600, 'auth', $token);
        return [$cookie, $nonce];
    }
    public static function post(string $path, array $fields, string $cookie = ''): array {
        $handle = curl_init('http://127.0.0.1:8080/' . $path);
        curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fields,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => ['Cookie: ' . $cookie]]);
        $raw = curl_exec($handle);
        if (!is_string($raw)) { throw new RuntimeException('Local test HTTP request failed.'); }
        $size = curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);
        return ['status' => $status, 'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size)];
    }
}
