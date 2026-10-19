<?php
/** admin/users.php -- kept so old bookmarks land on the staff directory. */
require_once __DIR__ . '/../includes/init.php';
require_role(['admin']);
redirect('/admin/staff.php');
