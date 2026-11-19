<?php

namespace eventiva\mcp;

use Craft;
use craft\base\Plugin;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use eventiva\mcp\services\AuditService;
use eventiva\mcp\services\ProtocolService;
use eventiva\mcp\services\ReviewService;
use eventiva\mcp\services\TokenService;
use yii\base\Event;

/**
 * MCP Server plugin.
 *
 * @property-read TokenService $tokens
 * @property-read AuditService $audit
 * @property-read ProtocolService $protocol
 * @property-read ReviewService $review
 */
class Mcp extends Plugin
{
    public string $schemaVersion = '1.1.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = false;

    public static function config(): array
    {
        return [
            'components' => [
                'tokens' => TokenService::class,
                'audit' => AuditService::class,
                'protocol' => ProtocolService::class,
                'review' => ReviewService::class,
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
                $event->rules['mcp'] = 'mcp-server/mcp/index';
            }
        );

        // Control panel
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['mcp-server'] = 'mcp-server/tokens/index';
                $event->rules['mcp-server/tokens'] = 'mcp-server/tokens/index';
                $event->rules['mcp-server/tokens/create'] = 'mcp-server/tokens/create';
                $event->rules['mcp-server/tokens/revoke'] = 'mcp-server/tokens/revoke';
                $event->rules['mcp-server/audit'] = 'mcp-server/tokens/audit';
                $event->rules['mcp-server/review'] = 'mcp-server/review/index';
                $event->rules['mcp-server/review/decide'] = 'mcp-server/review/decide';
            }
        );

        // A permission so editors (not only admins) can approve changes
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function (RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => 'MCP Server',
                    'permissions' => [
                        ReviewService::PERMISSION => ['label' => Craft::t('mcp-server', 'Approve or reject changes made by the MCP Server')],
                    ],
                ];
            }
        );
    }

    public function getCpNavItem(): ?array
    {
        $user = Craft::$app->getUser();
        $isAdmin = $user->getIsAdmin();
        $canApprove = $this->review->canApprove();

        if (!$isAdmin && !$canApprove) {
            return null;
        }

        $pending = $canApprove ? $this->review->pendingCount() : 0;

        $item = parent::getCpNavItem();
        $item['label'] = 'MCP';
        $item['url'] = $isAdmin ? 'mcp-server' : 'mcp-server/review';
        if ($pending > 0) {
            $item['badgeCount'] = $pending;
        }

        $subnav = [];
        if ($canApprove) {
            $subnav['review'] = ['label' => Craft::t('mcp-server', 'Approvals'), 'url' => 'mcp-server/review'] + ($pending > 0 ? ['badgeCount' => $pending] : []);
        }
        if ($isAdmin) {
            $subnav['tokens'] = ['label' => Craft::t('mcp-server', 'Tokens'), 'url' => 'mcp-server/tokens'];
            $subnav['audit'] = ['label' => Craft::t('mcp-server', 'Audit log'), 'url' => 'mcp-server/audit'];
        }
        $item['subnav'] = $subnav;

        return $item;
    }
}
