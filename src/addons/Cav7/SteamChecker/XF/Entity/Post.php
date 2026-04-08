<?php

namespace Cav7\SteamChecker\XF\Entity;

class Post extends XFCP_Post
{
    protected function _postSave()
    {
        parent::_postSave();

        $debug = \XF::options()->steamCheckerDebugLog ?? false;

        if ($debug) {
            \XF::logError('[VAC-DEBUG] Post::_postSave fired. post_id=' . $this->post_id
                . ' isInsert=' . ($this->isInsert() ? 'yes' : 'no')
                . ' user_id=' . $this->user_id
                . ' thread_id=' . $this->thread_id);
        }

        // Only fire on new posts, not edits.
        if (!$this->isInsert()) {
            return;
        }

        // Never react to the bot's own posts.
        $botUserId = (int) \XF::options()->steamCheckerBotUserId;
        if ($botUserId && $this->user_id === $botUserId) {
            if ($debug) { \XF::logError('[VAC-DEBUG] Post: skipping — posted by bot.'); }
            return;
        }

        // Only act within the configured enlistment node.
        $thread = $this->Thread;
        if (!$thread) {
            if ($debug) { \XF::logError('[VAC-DEBUG] Post: skipping — thread not found.'); }
            return;
        }
        $configuredNodeId = (int) \XF::options()->steamCheckerNodeId;
        if ($debug) {
            \XF::logError('[VAC-DEBUG] Post: thread_node_id=' . $thread->node_id
                . ' configured_node_id=' . $configuredNodeId);
        }
        if ($thread->node_id !== $configuredNodeId) {
            if ($debug) { \XF::logError('[VAC-DEBUG] Post: skipping — wrong node.'); }
            return;
        }

        // Parse allowed role IDs from the option (comma- or newline-separated).
        $rawRoles = trim((string) \XF::options()->steamCheckerAllowedRoleIds);
        if ($debug) { \XF::logError('[VAC-DEBUG] Post: rawRoles=' . var_export($rawRoles, true)); }
        if ($rawRoles === '') {
            if ($debug) { \XF::logError('[VAC-DEBUG] Post: skipping — no allowed roles configured.'); }
            return;
        }
        $allowedGroupIds = array_values(array_filter(
            array_map('intval', preg_split('/[\s,]+/', $rawRoles))
        ));
        if (empty($allowedGroupIds)) {
            if ($debug) { \XF::logError('[VAC-DEBUG] Post: skipping — allowed role list parsed to empty.'); }
            return;
        }

        // Check whether the posting user belongs to at least one allowed group.
        $user = $this->User;
        if (!$user) {
            if ($debug) { \XF::logError('[VAC-DEBUG] Post: skipping — user not found.'); }
            return;
        }
        $userGroupIds = array_merge(
            [(int) $user->user_group_id],
            array_map('intval', $user->secondary_group_ids ?: [])
        );
        if ($debug) {
            \XF::logError('[VAC-DEBUG] Post: allowedGroupIds=' . implode(',', $allowedGroupIds)
                . ' userGroupIds=' . implode(',', $userGroupIds));
        }
        if (!array_intersect($allowedGroupIds, $userGroupIds)) {
            if ($debug) { \XF::logError('[VAC-DEBUG] Post: skipping — user not in allowed groups.'); }
            return;
        }

        // Strip BBCode and HTML from the message, then look for the !vac command.
        $plain = preg_replace('/\[URL[^\]]*\](.*?)\[\/URL\]/is', '$1', $this->message);
        $plain = preg_replace('/\[[^\]]*\]/', ' ', $plain);
        $plain = html_entity_decode(strip_tags($plain), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if ($debug) { \XF::logError('[VAC-DEBUG] Post: plain message=' . $plain); }

        if (!preg_match('/!vac\s+(\S+)/i', $plain, $m)) {
            if ($debug) { \XF::logError('[VAC-DEBUG] Post: no !vac command found.'); }
            return;
        }

        $rawSteamId = trim($m[1]);

        if ($debug) {
            \XF::logError('[VAC-DEBUG] !vac invoked by user_id=' . $this->user_id
                . ' in thread_id=' . $thread->thread_id
                . ' rawSteamId=' . $rawSteamId);
        }

        try {
            $checker = new \Cav7\SteamChecker\SteamChecker($thread);
            $checker->runManual($rawSteamId);
        } catch (\Throwable $e) {
            \XF::logException($e, false, '[Cav7/SteamChecker] !vac invocation error: ');
        }
    }
}
