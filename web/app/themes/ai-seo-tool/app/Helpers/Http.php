<?php
namespace AISEO\Helpers;

class Http
{
    public static function ok($data = [], int $code = 200)
    {
        return new \WP_REST_Response([
            'status' => 'ok',
            'data' => $data,
        ], $code);
    }

    public static function fail(string $message, int $code = 400, array $extra = [])
    {
        return new \WP_REST_Response([
            'status' => 'error',
            'message' => $message,
            'extra' => $extra,
        ], $code);
    }

    public static function validate_token($request): bool
    {
        $token = $request->get_param('key');
        $expected = getenv('AISEO_SECURE_TOKEN') ?: '';
        return $expected && hash_equals($expected, (string) $token);
    }
}
