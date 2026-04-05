<?php

namespace Cav7\SteamChecker\XF\Entity;

class Thread extends XFCP_Thread
{
    protected function _postSave()
    {
        parent::_postSave();

        if (!$this->isInsert()) {
            return;
        }

        $configuredNodeId = (int) \XF::options()->steamCheckerNodeId;

        \XF::logError('[VAC-DEBUG] Thread _postSave: thread_id=' . $this->thread_id
            . ' node_id=' . $this->node_id
            . ' configured_node_id=' . $configuredNodeId);

        if ($this->node_id !== $configuredNodeId) {
            return;
        }

        try {
            $checker = new \Cav7\SteamChecker\SteamChecker($this);
            $checker->run();
        } catch (\Throwable $e) {
            \XF::logException($e, false, '[Cav7/SteamChecker] Unhandled error: ');
        }
    }
}
