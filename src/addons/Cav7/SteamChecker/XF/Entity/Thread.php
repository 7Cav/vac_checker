<?php

namespace Cav7\SteamChecker\XF\Entity;

class Thread extends XFCP_Thread
{
    protected function _postSave()
    {
        parent::_postSave();
        // Automatic VAC check is handled in XF\Entity\Post::_postSave() on the
        // OP (position 0). Triggering here caused first_post_id to be set to
        // the bot's post because the hook fires before XF saves the OP post.
    }
}
