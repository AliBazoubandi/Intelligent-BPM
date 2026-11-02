<?php
/**
 * گفتگوی هوشمند با فرآیندها — ناحیه تیم فرآیند
 */
require_once __DIR__ . '/../inc/chat_view.php';

$id = require_area('team');
handle_and_render_process_chat('team', $id, 'file.php?id=');

