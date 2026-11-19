<?php

namespace eventiva\mcp\controllers;

use Craft;
use craft\elements\User;
use craft\web\Controller;
use eventiva\mcp\Mcp;
use eventiva\mcp\records\ChangeRecord;
use eventiva\mcp\services\ReviewService;
use yii\web\Response;

/**
 * The approval queue in the control panel.
 */
class ReviewController extends Controller
{
    protected array|int|bool $allowAnonymous = false;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        // Admins always pass; other users need the "approve MCP changes" permission.
        $this->requirePermission(ReviewService::PERMISSION);
        return true;
    }

    public function actionIndex(): Response
    {
        $review = Mcp::getInstance()->review;
        $mode = Craft::$app->getRequest()->getQueryParam('status') === 'decided' ? 'decided' : 'pending';

        if ($mode === 'pending') {
            $changes = $review->listChanges(ChangeRecord::PENDING, 150);
        } else {
            $changes = array_values(array_filter(
                $review->listChanges('all', 150),
                fn(ChangeRecord $c) => $c->status !== ChangeRecord::PENDING
            ));
        }

        $batches = [];
        foreach ($changes as $change) {
            $batches[$change->batch ?: Craft::t('mcp-server', 'Unassigned')][] = [
                'change' => $change,
                'view' => $mode === 'pending' ? $review->present($change) : null,
            ];
        }

        $userIds = array_filter(array_unique(array_map(fn(ChangeRecord $c) => $c->decidedBy, $changes)));
        $users = [];
        if ($userIds) {
            foreach (User::find()->id($userIds)->status(null)->all() as $user) {
                $users[$user->id] = $user->friendlyName;
            }
        }

        return $this->renderTemplate('mcp-server/review', [
            'mode' => $mode,
            'batches' => $batches,
            'count' => count($changes),
            'pendingCount' => $review->pendingCount(),
            'users' => $users,
        ]);
    }

    /**
     * Approves or rejects the posted changes.
     *
     * POST fields: ids[] (selected items) + decision=approve|reject, or single="approve:ID" / "reject:ID",
     * note (reason for bulk rejections), notes[ID] (reason for a single rejection).
     */
    public function actionDecide(): Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();
        $review = Mcp::getInstance()->review;

        $decision = (string)$request->getBodyParam('decision', '');
        $ids = array_map('intval', (array)$request->getBodyParam('ids', []));
        $notes = (array)$request->getBodyParam('notes', []);
        $bulkNote = trim((string)$request->getBodyParam('note', ''));

        // A single button next to one item wins over the bulk selection.
        if ($single = (string)$request->getBodyParam('single', '')) {
            [$decision, $id] = array_pad(explode(':', $single, 2), 2, '');
            $ids = [(int)$id];
        }

        if (!in_array($decision, ['approve', 'reject'], true)) {
            return $this->redirect('mcp-server/review');
        }
        if (!$ids) {
            $this->setFailFlash(Craft::t('mcp-server', 'Please select at least one change.'));
            return $this->redirect('mcp-server/review');
        }

        $ok = 0;
        $errors = [];
        foreach ($ids as $id) {
            /** @var ChangeRecord|null $change */
            $change = ChangeRecord::findOne($id);
            if (!$change) {
                continue;
            }
            if ($decision === 'approve') {
                [$success, $message] = $review->approve($change);
            } else {
                $note = trim((string)($notes[$id] ?? '')) ?: $bulkNote;
                [$success, $message] = $review->reject($change, $note ?: null);
            }
            if ($success) {
                $ok++;
            } else {
                $errors[] = Craft::t('mcp-server', '“{label}”: {message}', ['label' => $change->label, 'message' => $message]);
            }
        }

        if ($errors) {
            $details = implode(' · ', array_slice($errors, 0, 3));
            $this->setFailFlash($decision === 'approve'
                ? Craft::t('mcp-server', '{count} approved, {problems} with problems: {details}', ['count' => $ok, 'problems' => count($errors), 'details' => $details])
                : Craft::t('mcp-server', '{count} rejected, {problems} with problems: {details}', ['count' => $ok, 'problems' => count($errors), 'details' => $details]));
        } else {
            $this->setSuccessFlash($decision === 'approve'
                ? Craft::t('mcp-server', '{count} approved.', ['count' => $ok])
                : Craft::t('mcp-server', '{count} rejected.', ['count' => $ok]));
        }

        return $this->redirect('mcp-server/review');
    }
}
