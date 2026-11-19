<?php

namespace eventiva\mcp\controllers;

use Craft;
use craft\elements\User;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use eventiva\mcp\Mcp;
use eventiva\mcp\services\TokenService;
use yii\web\Response;

class TokensController extends Controller
{
    protected array|int|bool $allowAnonymous = false;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requireAdmin(false);
        return true;
    }

    public function actionIndex(): Response
    {
        $mcp = Mcp::getInstance();

        $users = [];
        foreach (User::find()->status('active')->all() as $user) {
            $users[] = ['value' => $user->id, 'label' => $user->friendlyName . ($user->admin ? ' (Admin)' : '')];
        }

        $sections = array_map(
            fn($s) => ['value' => $s->handle, 'label' => $s->name],
            Craft::$app->getEntries()->getAllSections()
        );

        return $this->renderTemplate('mcp-server/tokens', [
            'tokens' => $mcp->tokens->all(),
            'users' => $users,
            'userNames' => array_column($users, 'label', 'value'),
            'sections' => $sections,
            'endpoint' => UrlHelper::siteUrl('mcp'),
            'newToken' => Craft::$app->getSession()->getFlash('mcpNewToken'),
        ]);
    }

    public function actionCreate(): Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();

        $name = trim((string)$request->getBodyParam('name'));
        $userId = (int)$request->getBodyParam('userId');
        if ($name === '' || !$userId) {
            $this->setFailFlash(Craft::t('mcp-server', 'Name and user are required.'));
            return $this->redirectToPostedUrl();
        }

        $scopes = array_intersect(TokenService::SCOPES, (array)$request->getBodyParam('scopes', []));
        $sections = array_filter((array)$request->getBodyParam('allowedSections', []));
        $expires = $request->getBodyParam('expiresAt');
        $expiryDate = is_array($expires) ? ($expires['date'] ?? '') : (string)$expires;
        // Expire at the end of the chosen day. The date picker may deliver Y-m-d or a localized date.
        $expiresAt = null;
        if ($expiryDate !== '') {
            $parsed = \DateTime::createFromFormat('Y-m-d', $expiryDate) ?: (\DateTime::createFromFormat('n/j/Y', $expiryDate) ?: (\DateTime::createFromFormat('d.m.Y', $expiryDate) ?: null));
            if ($parsed) {
                $expiresAt = $parsed->setTime(23, 59, 59);
            }
        }

        $requireReview = filter_var($request->getBodyParam('requireReview'), FILTER_VALIDATE_BOOLEAN);

        [, $plain] = Mcp::getInstance()->tokens->create($name, $userId, $scopes, $expiresAt, $sections ?: null, $requireReview);

        Craft::$app->getSession()->setFlash('mcpNewToken', $plain);
        return $this->redirect('mcp-server/tokens');
    }

    public function actionRevoke(): Response
    {
        $this->requirePostRequest();
        Mcp::getInstance()->tokens->revoke((int)Craft::$app->getRequest()->getRequiredBodyParam('id'));
        $this->setSuccessFlash(Craft::t('mcp-server', 'Token revoked.'));
        return $this->redirect('mcp-server/tokens');
    }

    public function actionAudit(): Response
    {
        return $this->renderTemplate('mcp-server/audit', [
            'entries' => Mcp::getInstance()->audit->recent(),
        ]);
    }
}
