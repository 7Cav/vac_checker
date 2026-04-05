<?php

namespace Cav7\SteamChecker;

class Listener
{
    /**
     * Tracks Thread entities that are being inserted (not updated).
     * Populated in entityPreSave; consumed and cleared in entityPostSave.
     *
     * @var \SplObjectStorage|null
     */
    private static $pendingNewThreads = null;

    /**
     * Fires before any entity save. We use this to detect inserts because
     * isInsert() returns false after the record has been written to the DB,
     * making entity_post_save alone unreliable for distinguishing inserts
     * from updates.
     *
     * Event: entity_pre_save  Hint: XF:Thread
     */
    public static function entityPreSave(\XF\Mvc\Entity\Entity $entity): void
    {
        if (!($entity instanceof \XF\Entity\Thread)) {
            return;
        }

        \XF::logError('[VAC-DEBUG] entityPreSave fired for Thread. isInsert=' . ($entity->isInsert() ? 'true' : 'false') . ' node_id=' . $entity->node_id);

        if (!$entity->isInsert()) {
            return;
        }

        if (self::$pendingNewThreads === null) {
            self::$pendingNewThreads = new \SplObjectStorage();
        }

        self::$pendingNewThreads->attach($entity);
        \XF::logError('[VAC-DEBUG] entityPreSave: Thread marked as pending new insert.');
    }

    /**
     * Fires after any entity save. Triggers the VAC check when a new thread
     * is created in the configured enlistment node.
     *
     * Event: entity_post_save  Hint: XF:Thread
     */
    public static function entityPostSave(\XF\Mvc\Entity\Entity $entity): void
    {
        if (!($entity instanceof \XF\Entity\Thread)) {
            return;
        }

        \XF::logError('[VAC-DEBUG] entityPostSave fired for Thread id=' . $entity->thread_id . ' node_id=' . $entity->node_id);

        if (self::$pendingNewThreads === null || !self::$pendingNewThreads->contains($entity)) {
            \XF::logError('[VAC-DEBUG] entityPostSave: Thread not in pending set — skipping (was an update, not an insert).');
            return;
        }

        // Consume the marker regardless of what follows so we never double-fire.
        self::$pendingNewThreads->detach($entity);

        $configuredNodeId = (int) \XF::options()->steamCheckerNodeId;
        \XF::logError('[VAC-DEBUG] entityPostSave: thread node_id=' . $entity->node_id . ' configured node_id=' . $configuredNodeId);

        if ($entity->node_id !== $configuredNodeId) {
            \XF::logError('[VAC-DEBUG] entityPostSave: Node ID mismatch — not an enlistment thread, skipping.');
            return;
        }

        \XF::logError('[VAC-DEBUG] entityPostSave: Launching SteamChecker for thread ' . $entity->thread_id);

        try {
            $checker = new SteamChecker($entity);
            $checker->run();
        } catch (\Throwable $e) {
            \XF::logException($e, false, '[Cav7/SteamChecker] Unhandled error: ');
        }
    }
}
