<?php
/**
 * گفتگوی هوشمند با فرآیندها — ناحیه کاربر
 */
require_once __DIR__ . '/../inc/chat_view.php';

$id = require_area('user');
handle_and_render_process_chat('user', $id, 'processes.php?id=');

