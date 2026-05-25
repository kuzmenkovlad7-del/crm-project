<?php
/**
 * 
 * DO_NOT_REMOVE
 * Developer by RhythmDev.top
 * 
**/

add_filter('wsal_custom_alerts', function($alerts) {
	$alerts[9001] = array(
		'title'    => 'Открытие чата',
		'text'     => 'Пользователь %Username%(%UserID%) %Action%',
		'severity' => 'info',
		'auditlog' => true,
		'enabled'  => true,
		'fields'   => array('Username', 'UserID', 'Action'),
	);

	$alerts[9002] = array(
		'title'    => 'Удаление контакта',
		'text'     => 'Пользователь %Username%(%UserID%) %Action%',
		'severity' => 'warning',
		'auditlog' => true,
		'enabled'  => true,
		'fields'   => array('Username', 'UserID', 'Action'),
	);

	$alerts[9003] = array(
		'title'    => 'Действия с избранными',
		'text'     => 'Пользователь %Username%(%UserID%) %Action%',
		'severity' => 'info',
		'auditlog' => true,
		'enabled'  => true,
		'fields'   => array('Username', 'UserID', 'Action'),
	);

	$alerts[9004] = array(
		'title'    => 'Отправка сообщения',
		'text'     => 'Пользователь %Username%(%UserID%) %Action%',
		'severity' => 'info',
		'auditlog' => true,
		'enabled'  => true,
		'fields'   => array('Username', 'UserID', 'Action'),
	);

	return $alerts;
});


