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

        return $this->renderTemplate('mcp/tokens', [
            'tokens' => $mcp->tokens->all(),
            'users' => $users,
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
            $this->setFailFlash('Name und Benutzer sind erforderlich.');
            return $this->redirectToPostedUrl();
        }

        $scopes = array_intersect(TokenService::SCOPES, (array)$request->getBodyParam('scopes', []));
        $sections = array_filter((array)$request->getBodyParam('allowedSections', []));
        $expires = $request->getBodyParam('expiresAt');
        $expiresAt = $expires ? (\DateTime::createFromFormat('Y-m-d', is_array($expires) ? ($expires['date'] ?? '') : $expires) ?: null) : null;

        [, $plain] = Mcp::getInstance()->tokens->create($name, $userId, $scopes, $expiresAt, $sections ?: null);

        Craft::$app->getSession()->setFlash('mcpNewToken', $plain);
        return $this->redirect('mcp/tokens');
    }

    public function actionRevoke(): Response
    {
        $this->requirePostRequest();
        Mcp::getInstance()->tokens->revoke((int)Craft::$app->getRequest()->getRequiredBodyParam('id'));
        $this->setSuccessFlash('Token widerrufen.');
        return $this->redirect('mcp/tokens');
    }

    public function actionAudit(): Response
    {
        return $this->renderTemplate('mcp/audit', [
            'entries' => Mcp::getInstance()->audit->recent(),
        ]);
    }
}
