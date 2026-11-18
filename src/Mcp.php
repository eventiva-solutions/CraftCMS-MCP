<?php

namespace eventiva\mcp;

use Craft;
use craft\base\Plugin;
use craft\events\RegisterUrlRulesEvent;
use craft\web\UrlManager;
use eventiva\mcp\services\AuditService;
use eventiva\mcp\services\ProtocolService;
use eventiva\mcp\services\TokenService;
use yii\base\Event;

/**
 * MCP Server plugin.
 *
 * @property-read TokenService $tokens
 * @property-read AuditService $audit
 * @property-read ProtocolService $protocol
 */
class Mcp extends Plugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = false;

    public static function config(): array
    {
        return [
            'components' => [
                'tokens' => TokenService::class,
                'audit' => AuditService::class,
                'protocol' => ProtocolService::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        // Public MCP endpoint (Streamable HTTP): POST /mcp
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['mcp'] = 'mcp/mcp/index';
            }
        );

        // Control panel
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['mcp'] = 'mcp/tokens/index';
                $event->rules['mcp/tokens'] = 'mcp/tokens/index';
                $event->rules['mcp/tokens/create'] = 'mcp/tokens/create';
                $event->rules['mcp/tokens/revoke'] = 'mcp/tokens/revoke';
                $event->rules['mcp/audit'] = 'mcp/tokens/audit';
            }
        );
    }

    public function getCpNavItem(): ?array
    {
        if (!Craft::$app->getUser()->getIsAdmin()) {
            return null;
        }

        $item = parent::getCpNavItem();
        $item['label'] = 'MCP';
        $item['url'] = 'mcp';
        $item['icon'] = '@appicons/plug.svg';
        $item['subnav'] = [
            'tokens' => ['label' => 'Tokens', 'url' => 'mcp/tokens'],
            'audit' => ['label' => 'Audit-Log', 'url' => 'mcp/audit'],
        ];
        return $item;
    }
}
