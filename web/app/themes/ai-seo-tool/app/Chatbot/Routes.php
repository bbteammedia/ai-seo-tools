<?php
namespace BBSEO\Chatbot;

use WP_REST_Request;
use WP_REST_Response;

class Routes
{
    public static function register(): void
    {
        register_rest_route('chatbot/v1', '/identify', [
            'methods' => 'POST',
            'callback' => [self::class, 'identify'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('chatbot/v1', '/sessions', [
            'methods' => 'GET',
            'callback' => [self::class, 'listSessions'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('chatbot/v1', '/session', [
            'methods' => 'GET',
            'callback' => [self::class, 'getSession'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('chatbot/v1', '/session', [
            'methods' => 'POST',
            'callback' => [self::class, 'createSession'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('chatbot/v1', '/message', [
            'methods' => 'POST',
            'callback' => [self::class, 'sendMessage'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('chatbot/v1', '/action', [
            'methods' => 'POST',
            'callback' => [self::class, 'performAction'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function identify(WP_REST_Request $request): WP_REST_Response
    {
        $payload = self::validateIdentity($request);
        if (is_wp_error($payload)) {
            return new WP_REST_Response(['message' => $payload->get_error_message()], 422);
        }
        $result = Service::identify($payload['name'], $payload['email']);
        return new WP_REST_Response($result, 200);
    }

    public static function listSessions(WP_REST_Request $request): WP_REST_Response
    {
        $email = sanitize_email((string) $request->get_param('email'));
        if (!$email) {
            return new WP_REST_Response(['message' => __('Email is required.', 'ai-seo-tool')], 422);
        }
        $sessions = Service::listSessions($email);
        return new WP_REST_Response(['sessions' => $sessions], 200);
    }

    public static function getSession(WP_REST_Request $request): WP_REST_Response
    {
        $email = sanitize_email((string) $request->get_param('email'));
        $sessionId = sanitize_text_field((string) $request->get_param('session_id'));
        if (!$email || !$sessionId) {
            return new WP_REST_Response(['message' => __('Email and session_id are required.', 'ai-seo-tool')], 422);
        }
        $session = Service::getSession($email, $sessionId);
        if (!$session) {
            return new WP_REST_Response(['message' => __('Session not found.', 'ai-seo-tool')], 404);
        }
        return new WP_REST_Response(\BBSEO\Chatbot\Service::formatSessionResponse($session), 200);
    }

    public static function createSession(WP_REST_Request $request): WP_REST_Response
    {
        $payload = self::validateIdentity($request);
        if (is_wp_error($payload)) {
            return new WP_REST_Response(['message' => $payload->get_error_message()], 422);
        }
        $session = Service::createSession($payload['name'], $payload['email']);
        if (!$session) {
            return new WP_REST_Response(['message' => __('Unable to create session.', 'ai-seo-tool')], 500);
        }
        return new WP_REST_Response($session, 201);
    }

    public static function sendMessage(WP_REST_Request $request): WP_REST_Response
    {
        $payload = self::validateIdentity($request);
        if (is_wp_error($payload)) {
            return new WP_REST_Response(['message' => $payload->get_error_message()], 422);
        }
        $sessionId = sanitize_text_field((string) $request->get_param('session_id'));
        $message = sanitize_textarea_field((string) $request->get_param('message'));
        if (!$sessionId || !$message) {
            return new WP_REST_Response(['message' => __('Session ID and message are required.', 'ai-seo-tool')], 422);
        }
        $result = Service::sendMessage($payload['name'], $payload['email'], $sessionId, $message);
        if (!$result) {
            return new WP_REST_Response(['message' => __('Unable to send message. Session missing?', 'ai-seo-tool')], 404);
        }
        return new WP_REST_Response($result, 200);
    }

    public static function performAction(WP_REST_Request $request): WP_REST_Response
    {
        $payload = self::validateIdentity($request);
        if (is_wp_error($payload)) {
            return new WP_REST_Response(['message' => $payload->get_error_message()], 422);
        }
        $sessionId = sanitize_text_field((string) $request->get_param('session_id'));
        $action = sanitize_key((string) $request->get_param('action'));
        if (!$sessionId || !$action) {
            return new WP_REST_Response(['message' => __('Action and session ID are required.', 'ai-seo-tool')], 422);
        }
        $result = Service::performAction($action, $payload['name'], $payload['email'], $sessionId);
        if (!$result) {
            return new WP_REST_Response(['message' => __('Unable to process action.', 'ai-seo-tool')], 404);
        }
        return new WP_REST_Response($result, 200);
    }

    private static function validateIdentity(WP_REST_Request $request)
    {
        $name = sanitize_text_field((string) $request->get_param('name'));
        $email = sanitize_email((string) $request->get_param('email'));
        if (!$name) {
            return new \WP_Error('invalid_name', __('Name is required.', 'ai-seo-tool'));
        }
        if (!$email) {
            return new \WP_Error('invalid_email', __('Valid email is required.', 'ai-seo-tool'));
        }
        return [
            'name' => $name,
            'email' => $email,
        ];
    }
}
