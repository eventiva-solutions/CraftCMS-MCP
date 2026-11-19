<?php

namespace eventiva\mcp\controllers;

use Craft;
use craft\elements\User;
use craft\web\Controller;
use eventiva\mcp\Mcp;
use yii\web\Response;

/**
 * MCP endpoint (Streamable HTTP): POST /mcp with a Bearer token.
 */
class McpController extends Controller
{
    protected array|int|bool $allowAnonymous = self::ALLOW_ANONYMOUS_LIVE;

    public $enableCsrfValidation = false;

    public function actionIndex(): Response
    {
        $request = Craft::$app->getRequest();
        $response = Craft::$app->getResponse();
        $response->setNoCacheHeaders();

        if (!$request->getIsPost()) {
            $response->setStatusCode(405);
            $response->getHeaders()->set('Allow', 'POST');
            return $this->asJson(['error' => 'Use POST with a JSON-RPC body.']);
        }

        // --- Authentication -------------------------------------------------
        $plain = $this->extractToken();
        $mcp = Mcp::getInstance();
        $token = $plain !== null ? $mcp->tokens->authenticate($plain) : null;
        $user = $token ? User::find()->id($token->userId)->status('active')->one() : null;

        if (!$token || !$user) {
            $response->setStatusCode(401);
            $response->getHeaders()->set('WWW-Authenticate', 'Bearer realm="craft-mcp"');
            return $this->asJson(['error' => 'Invalid or missing token.']);
        }

        // Rate limit: 120 requests / minute / token.
        // The counter is incremented under a mutex so parallel requests cannot overwrite each other.
        $cache = Craft::$app->getCache();
        $mutex = Craft::$app->getMutex();
        $lock = 'mcp-rate-' . $token->id;
        $key = 'mcp_rate_' . $token->id . '_' . (int)floor(time() / 60);
        $count = 0;
        if ($mutex->acquire($lock, 3)) {
            try {
                $count = (int)$cache->get($key) + 1;
                $cache->set($key, $count, 120);
            } finally {
                $mutex->release($lock);
            }
        }
        if ($count > 120) {
            $response->setStatusCode(429);
            $response->getHeaders()->set('Retry-After', '60');
            return $this->asJson(['error' => 'Rate limit exceeded (120 requests/minute).']);
        }

        // Run everything as the token's user, so Craft permissions apply.
        Craft::$app->getUser()->setIdentity($user);
        $mcp->tokens->current = $token;

        // --- JSON-RPC ---------------------------------------------------------
        $body = json_decode($request->getRawBody(), true);
        if ($body === null) {
            return $this->asJson(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']]);
        }

        // Batch
        if (is_array($body) && array_is_list($body) && $body !== []) {
            $results = array_values(array_filter(
                array_map(fn($m) => $mcp->protocol->handle($m, $token), $body)
            ));
            if (!$results) {
                $response->setStatusCode(202);
                return $this->asRaw('');
            }
            return $this->asJson($results);
        }

        $result = $mcp->protocol->handle($body, $token);
        if ($result === null) {
            $response->setStatusCode(202);
            return $this->asRaw('');
        }

        return $this->asJson($result);
    }

    private function extractToken(): ?string
    {
        $headers = Craft::$app->getRequest()->getHeaders();
        $auth = $headers->get('Authorization')
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? null;

        if ($auth && preg_match('/^Bearer\s+(\S+)$/i', $auth, $m)) {
            return $m[1];
        }

        // Fallback for hosts that strip the Authorization header.
        return $headers->get('X-Mcp-Token') ?: null;
    }
}
