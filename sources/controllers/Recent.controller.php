<?php

/**
 * Find and retrieve information about recently posted topics, messages, and the like.
 *
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * This software is a derived product, based on:
 *
 * Simple Machines Forum (SMF)
 * copyright:	2011 Simple Machines (http://www.simplemachines.org)
 * license:		BSD, See included LICENSE.TXT for terms and conditions.
 *
 * @version 1.0 Beta 2
 *
 */

if (!defined('ELK'))
	die('No access...');

/**
 * Recent Post Controller, retrieve information about recent posts
 */
class Recent_Controller extends Action_Controller
{
	private $_have_temp_table = false;
	private $_query_this_board = '';
	private $_preview_bodies = 0;
	private $_boards = array();
	private $_is_topics = false;
	private $_ascending = false;
	private $_sort_query = '';
	private $_num_topics = 0;

	/**
	 * Parameters for the main query.
	 */
	private $_query_parameters = array();

	/**
	 * Called before any other action method in this class.
	 *
	 * - Allows for initializations, such as default values or
	 * loading templates or language files.
	 */
	public function pre_dispatch()
	{
		// Guests can't have unread things, we don't know anything about them.
		is_not_guest();

		// Prefetching + lots of MySQL work = bad mojo.
		if (isset($_SERVER['HTTP_X_MOZ']) && $_SERVER['HTTP_X_MOZ'] == 'prefetch')
		{
			ob_end_clean();
			header('HTTP/1.1 403 Forbidden');
			die;
		}

		require_once(SUBSDIR . '/Recent.subs.php');
		require_once(SUBSDIR . '/Boards.subs.php');
	}

	/**
	 * Intended entry point for recent controller class.
	 *
	 * @see Action_Controller::action_index()
	 */
	public function action_index()
	{
		// Figure out what action to do
		$this->action_recent();
	}

	/**
	 * Find the ten most recent posts.
	 * Accessed by action=recent.
	 */
	public function action_recent()
	{
		global $txt, $scripturl, $user_info, $context, $modSettings, $board;

		loadTemplate('Recent');
		$context['page_title'] = $txt['recent_posts'];
		$context['sub_template'] = 'recent';

		if (isset($_REQUEST['start']) && $_REQUEST['start'] > 95)
			$_REQUEST['start'] = 95;

		$query_parameters = array();

		// Recent posts by category id's
		if (!empty($_REQUEST['c']) && empty($board))
		{
			$categories = array_map('intval', explode(',', $_REQUEST['c']));

			if (count($categories) === 1)
			{
				require_once(SUBSDIR . '/Categories.subs.php');
				$name = categoryName($categories[0]);

				if (empty($name))
					fatal_lang_error('no_access', false);

				$context['linktree'][] = array(
					'url' => $scripturl . '#c' . $categories[0],
					'name' => $name
				);
			}

			// Find the number of posts in these categorys, exclude the recycle board.
			$boards_posts = boardsPosts(array(), $categories, false, false);
			$total_posts = array_sum($boards_posts);
			$boards = array_keys($boards_posts);

			if (empty($boards))
				fatal_lang_error('error_no_boards_selected');

			// The query for getting the messages
			$query_this_board = 'b.id_board IN ({array_int:boards})';
			$query_parameters['boards'] = $boards;

			// If this category has a significant number of posts in it...
			if ($total_posts > 100 && $total_posts > $modSettings['totalMessages'] / 15)
			{
				$query_this_board .= '
						AND m.id_msg >= {int:max_id_msg}';
				$query_parameters['max_id_msg'] = max(0, $modSettings['maxMsgID'] - 400 - $_REQUEST['start'] * 7);
			}

			$context['page_index'] = constructPageIndex($scripturl . '?action=recent;c=' . implode(',', $categories), $_REQUEST['start'], min(100, $total_posts), 10, false);
		}
		// Or recent posts by board id's?
		elseif (!empty($_REQUEST['boards']))
		{
			$_REQUEST['boards'] = explode(',', $_REQUEST['boards']);
			foreach ($_REQUEST['boards'] as $i => $b)
				$_REQUEST['boards'][$i] = (int) $b;

			// Fetch the number of posts for the supplied board IDs
			$boards_posts = boardsPosts($_REQUEST['boards'], array());
			$total_posts = array_sum($boards_posts);
			$boards = array_keys($boards_posts);

			if (empty($boards))
				fatal_lang_error('error_no_boards_selected');

			// Build the query for finding the messages
			$query_this_board = 'b.id_board IN ({array_int:boards})';
			$query_parameters['boards'] = $boards;

			// If these boards have a significant number of posts in them...
			if ($total_posts > 100 && $total_posts > $modSettings['totalMessages'] / 12)
			{
				$query_this_board .= '
						AND m.id_msg >= {int:max_id_msg}';
				$query_parameters['max_id_msg'] = max(0, $modSettings['maxMsgID'] - 500 - $_REQUEST['start'] * 9);
			}

			$context['page_index'] = constructPageIndex($scripturl . '?action=recent;boards=' . implode(',', $_REQUEST['boards']), $_REQUEST['start'], min(100, $total_posts), 10, false);
		}
		// Or just the recent posts for a specific board
		elseif (!empty($board))
		{
			$board_data = fetchBoardsInfo(array('boards' => $board), array('selects' => 'posts'));
			$total_posts = $board_data[$board]['num_posts'];

			$query_this_board = 'b.id_board = {int:board}';
			$query_parameters['board'] = $board;

			// If this board has a significant number of posts in it...
			if ($total_posts > 80 && $total_posts > $modSettings['totalMessages'] / 10)
			{
				$query_this_board .= '
						AND m.id_msg >= {int:max_id_msg}';
				$query_parameters['max_id_msg'] = max(0, $modSettings['maxMsgID'] - 600 - $_REQUEST['start'] * 10);
			}

			$context['page_index'] = constructPageIndex($scripturl . '?action=recent;board=' . $board . '.%1$d', $_REQUEST['start'], min(100, $total_posts), 10, true);
		}
		// All the recent posts across boards and categories it is then
		else
		{
			$total_posts = sumRecentPosts();

			$query_this_board = '{query_wanna_see_board}' . (!empty($modSettings['recycle_enable']) && $modSettings['recycle_board'] > 0 ? '
						AND b.id_board != {int:recycle_board}' : '') . '
						AND m.id_msg >= {int:max_id_msg}';
			$query_parameters['max_id_msg'] = max(0, $modSettings['maxMsgID'] - 100 - $_REQUEST['start'] * 6);
			$query_parameters['recycle_board'] = $modSettings['recycle_board'];

			// Set up the pageindex
			$context['page_index'] = constructPageIndex($scripturl . '?action=recent', $_REQUEST['start'], min(100, $total_posts), 10, false);
		}

		$context['linktree'][] = array(
			'url' => $scripturl . '?action=recent' . (empty($board) ? (empty($categories) ? '' : ';c=' . implode(',', $categories)) : ';board=' . $board . '.0'),
			'name' => $context['page_title']
		);

		$messages = findRecentMessages($query_parameters, $query_this_board, (int) $_REQUEST['start'], 10);

		// Nothing here... Or at least, nothing you can see...
		if (empty($messages))
		{
			$context['posts'] = array();
			return;
		}

		list ($context['posts'], $board_ids) = getRecentPosts($messages, $_REQUEST['start']);

		// There might be - and are - different permissions between any and own.
		$permissions = array(
			'own' => array(
				'post_reply_own' => 'can_reply',
				'delete_own' => 'can_delete',
			),
			'any' => array(
				'post_reply_any' => 'can_reply',
				'mark_any_notify' => 'can_mark_notify',
				'delete_any' => 'can_delete',
			)
		);

		// Now go through all the permissions, looking for boards they can do it on.
		foreach ($permissions as $type => $list)
		{
			foreach ($list as $permission => $allowed)
			{
				// They can do it on these boards...
				$boards = boardsAllowedTo($permission);

				// If 0 is the only thing in the array, they can do it everywhere!
				if (!empty($boards) && $boards[0] == 0)
					$boards = array_keys($board_ids[$type]);

				// Go through the boards, and look for posts they can do this on.
				foreach ($boards as $board_id)
				{
					// Hmm, they have permission, but there are no topics from that board on this page.
					if (!isset($board_ids[$type][$board_id]))
						continue;

					// Okay, looks like they can do it for these posts.
					foreach ($board_ids[$type][$board_id] as $counter)
						if ($type == 'any' || $context['posts'][$counter]['poster']['id'] == $user_info['id'])
							$context['posts'][$counter]['tests'][$allowed] = true;
				}
			}
		}

		$quote_enabled = empty($modSettings['disabledBBC']) || !in_array('quote', explode(',', $modSettings['disabledBBC']));
		foreach ($context['posts'] as $counter => $post)
		{
			// Some posts - the first posts - can't just be deleted.
			$context['posts'][$counter]['tests']['can_delete'] &= $context['posts'][$counter]['delete_possible'];

			// And some cannot be quoted...
			$context['posts'][$counter]['tests']['can_quote'] = $context['posts'][$counter]['tests']['can_reply'] && $quote_enabled;

			// Let's add some buttons here!
			$context['posts'][$counter]['buttons'] = array(
				// How about... even... remove it entirely?!
				'remove' => array(
					'href' => $scripturl . '?action=deletemsg;msg=' . $post['id'] . ';topic=' . $post['topic'] . ';recent;' . $context['session_var'] . '=' . $context['session_id'],
					'text' => $txt['remove'],
					'test' => 'can_delete',
					'custom' => 'onclick="return confirm(' . JavaScriptEscape($txt['remove_message'] . '?') . ');"',
				),
				// Can we request notification of topics?
				'notify' => array(
					'href' => $scripturl . '?action=notify;topic=' . $post['topic'] . '.' . $post['start'],
					'text' => $txt['notify'],
					'test' => 'can_mark_notify',
				),
				// If they *can* reply?
				'reply' => array(
					'href' => $scripturl . '?action=post;topic=' . $post['topic'] . '.' . $post['start'],
					'text' => $txt['reply'],
					'test' => 'can_reply',
				),
				// If they *can* quote?
				'quote' => array(
					'href' => $scripturl . '?action=post;topic=' . $post['topic'] . '.' . $post['start'] . ';quote=' . $post['id'],
					'text' => $txt['quote'],
					'test' => 'can_quote',
				),
			);
		}
	}

	/**
	 * Find unread topics.
	 * Accessed by action=unread
	 */
	public function action_unread()
	{
		global $board, $context, $modSettings, $settings;

		$this->_entering_unread();

		$earliest_msg = 0;
		if ($context['showing_all_topics'])
			$earliest_msg = earliest_msg();

		// @todo Add modified_time in for log_time check?
		// Let's copy things out of the log_topics table, to reduce searching.
		if ($modSettings['totalMessages'] > 100000 && $context['showing_all_topics'])
			$this->_have_temp_table = recent_log_topics_unread_tempTable($this->_query_parameters, $earliest_msg);

		// All unread replies with temp table
		if ($context['showing_all_topics'] && $this->_have_temp_table)
		{
			list ($this->_num_topics, $min_message) = countRecentTopics($this->_query_parameters, $context['showing_all_topics'], true, false, $earliest_msg);

			if ($this->_num_topics == 0)
			{
				// Mark the boards as read if there are no unread topics!
				// @todo look at this... there are no more unread topics already.
				// If clearing of log_topics is still needed, perhaps do it separately.
				markBoardsRead(empty($this->_boards) ? $board : $this->_boards, false, true);

				$context['topics'] = array();
				if ($context['querystring_board_limits'] == ';start=%1$d')
					$context['querystring_board_limits'] = '';
				else
					$context['querystring_board_limits'] = sprintf($context['querystring_board_limits'], $_REQUEST['start']);

				return;
			}
			else
				$min_message = (int) $min_message;

			$context['topics'] = getUnreadTopics($this->_query_parameters, $this->_preview_bodies, 'message', $this->_have_temp_table, $min_message, $this->_sort_query, $this->_ascending, $_REQUEST['start'], $context['topics_per_page'], !empty($settings['avatars_on_indexes']));
		}
		// New posts with or without temp table
		elseif ($this->_is_topics)
		{
			list ($this->_num_topics, $min_message) = countRecentTopics($this->_query_parameters, $context['showing_all_topics'], $this->_have_temp_table, empty($_SESSION['first_login']), $earliest_msg, $_SESSION['id_msg_last_visit']);

			if ($this->_num_topics == 0)
			{
				// Is this an all topics query?
				if ($context['showing_all_topics'])
				{
					// Since there are no unread topics, mark the boards as read!
					// @todo look at this... there are no more unread topics already.
					// If clearing of log_topics is still needed, perhaps do it separately.
					markBoardsRead(empty($this->_boards) ? $board : $this->_boards, false, true);
				}

				$context['topics'] = array();
				if ($context['querystring_board_limits'] == ';start=%d')
					$context['querystring_board_limits'] = '';
				else
					$context['querystring_board_limits'] = sprintf($context['querystring_board_limits'], $_REQUEST['start']);
				return;
			}
			else
				$min_message = (int) $min_message;

			$context['topics'] = getUnreadTopics($this->_query_parameters, $this->_preview_bodies, 'topics', $this->_have_temp_table, $min_message, $this->_sort_query, $this->_ascending, $_REQUEST['start'], $context['topics_per_page'], !empty($settings['avatars_on_indexes']));
		}
		// Does it make sense?... Dunno.
		else
			return $this->action_unreadreplies();

		$this->_exiting_unread();
	}

	/**
	 * Find unread replies.
	 * Accessed by action=unreadreplies
	 */
	public function action_unreadreplies()
	{
		global $board, $scripturl, $context, $modSettings, $settings;

		$this->_entering_unread();

		if ($modSettings['totalMessages'] > 100000)
			$this->_have_temp_table = unreadreplies_tempTable(!empty($board) ? $board : 0, $this->_sort_query);

		list ($this->_num_topics, $min_message) = countUnreadReplies($this->_query_parameters, $this->_have_temp_table);

		$context['links'] += array(
			'first' => $_REQUEST['start'] >= $context['topics_per_page'] ? $scripturl . '?action=' . $_REQUEST['action'] . ($context['showing_all_topics'] ? ';all' : '') . sprintf($context['querystring_board_limits'], 0) . $context['querystring_sort_limits'] : '',
			'last' => $_REQUEST['start'] + $context['topics_per_page'] < $this->_num_topics ? $scripturl . '?action=' . $_REQUEST['action'] . ($context['showing_all_topics'] ? ';all' : '') . sprintf($context['querystring_board_limits'], floor(($this->_num_topics - 1) / $context['topics_per_page']) * $context['topics_per_page']) . $context['querystring_sort_limits'] : '',
			'up' => $scripturl,
		);
		$context['page_info'] = array(
			'current_page' => $_REQUEST['start'] / $context['topics_per_page'] + 1,
			'num_pages' => floor(($this->_num_topics - 1) / $context['topics_per_page']) + 1
		);

		if ($this->_num_topics == 0)
		{
			$context['topics'] = array();
			if ($context['querystring_board_limits'] == ';start=%d')
				$context['querystring_board_limits'] = '';
			else
				$context['querystring_board_limits'] = sprintf($context['querystring_board_limits'], $_REQUEST['start']);
			return;
		}

		$context['topics'] = getUnreadReplies($this->_query_parameters, $this->_preview_bodies, $this->_have_temp_table, $min_message, $this->_sort_query, $this->_ascending, $_REQUEST['start'], $context['topics_per_page'], !empty($settings['avatars_on_indexes']));

		if ($context['topics'] === false)
		{
				$context['topics'] = array();
			if ($context['querystring_board_limits'] == ';start=%d')
				$context['querystring_board_limits'] = '';
			else
				$context['querystring_board_limits'] = sprintf($context['querystring_board_limits'], $_REQUEST['start']);
			return;
		}

		$this->_exiting_unread();
	}

	/**
	 * Some common things done at the beginning of each action,
	 * but after pre_dispatch.
	 */
	private function _entering_unread()
	{
		global $txt, $scripturl, $context, $settings, $modSettings, $options;

		// We need... we need... I know!
		require_once(SUBSDIR . '/Boards.subs.php');

		$context['showCheckboxes'] = !empty($options['display_quick_mod']) && $options['display_quick_mod'] == 1 && $settings['show_mark_read'];
		$context['showing_all_topics'] = isset($_GET['all']);
		$context['start'] = (int) $_REQUEST['start'];
		$context['topics_per_page'] = empty($modSettings['disableCustomPerPage']) && !empty($options['topics_per_page']) ? $options['topics_per_page'] : $modSettings['defaultMaxTopics'];

		if ($_REQUEST['action'] == 'unread')
			$context['page_title'] = $context['showing_all_topics'] ? $txt['unread_topics_all'] : $txt['unread_topics_visit'];
		else
			$context['page_title'] = $txt['unread_replies'];

		if ($context['showing_all_topics'] && !empty($modSettings['loadavg_allunread']) && $modSettings['current_load'] >= $modSettings['loadavg_allunread'])
			fatal_lang_error('loadavg_allunread_disabled', false);
		elseif ($_REQUEST['action'] != 'unread' && !empty($modSettings['loadavg_unreadreplies']) && $modSettings['current_load'] >= $modSettings['loadavg_unreadreplies'])
			fatal_lang_error('loadavg_unreadreplies_disabled', false);
		elseif (!$context['showing_all_topics'] && $_REQUEST['action'] == 'unread' && !empty($modSettings['loadavg_unread']) && $modSettings['current_load'] >= $modSettings['loadavg_unread'])
			fatal_lang_error('loadavg_unread_disabled', false);

		// Are we specifying any specific board?
		$this->_wanted_boards();
		$this->_sorting_conditions();

		if (!empty($_REQUEST['c']) && is_array($_REQUEST['c']) && count($_REQUEST['c']) == 1)
		{
			$name = categoryName((int) $_REQUEST['c'][0]);

			$context['linktree'][] = array(
				'url' => $scripturl . '#c' . (int) $_REQUEST['c'][0],
				'name' => $name
			);
		}

		$context['linktree'][] = array(
			'url' => $scripturl . '?action=' . $_REQUEST['action'] . sprintf($context['querystring_board_limits'], 0) . $context['querystring_sort_limits'],
			'name' => $_REQUEST['action'] == 'unread' ? $txt['unread_topics_visit'] : $txt['unread_replies']
		);

		if ($context['showing_all_topics'])
			$context['linktree'][] = array(
				'url' => $scripturl . '?action=' . $_REQUEST['action'] . ';all' . sprintf($context['querystring_board_limits'], 0) . $context['querystring_sort_limits'],
				'name' => $txt['unread_topics_all']
			);
		else
			$txt['unread_topics_visit_none'] = strtr($txt['unread_topics_visit_none'], array('?action=unread;all' => '?action=unread;all' . sprintf($context['querystring_board_limits'], 0) . $context['querystring_sort_limits']));

		loadTemplate('Recent');
		$context['sub_template'] = 'unread';
		$context['unread_header_title'] = $_REQUEST['action'] == 'unread' ? ($context['showing_all_topics'] ? $txt['unread_topics_all'] : $txt['unread_topics_visit']) : $txt['unread_replies'];

		// Setup the default topic icons... for checking they exist and the like ;)
		require_once(SUBSDIR . '/MessageIndex.subs.php');
		$context['icon_sources'] = MessageTopicIcons();

		$this->_is_topics = $_REQUEST['action'] == 'unread';

		// If empty, no preview at all
		if (empty($modSettings['message_index_preview']))
			$this->_preview_bodies = 0;
		// If 0 means everything
		elseif (empty($modSettings['preview_characters']))
			$this->_preview_bodies = 'all';
		// Default: a SUBSTRING
		else
			$this->_preview_bodies = $modSettings['preview_characters'];
	}

	/**
	 * Finds out the boards the user want.
	 */
	private function _wanted_boards()
	{
		global $board;

		if (isset($_REQUEST['children']) && (!empty($board) || !empty($_REQUEST['boards'])))
		{
			$this->_boards = array();

			if (!empty($_REQUEST['boards']))
			{
				$_REQUEST['boards'] = explode(',', $_REQUEST['boards']);
				foreach ($_REQUEST['boards'] as $b)
					$this->_boards[] = (int) $b;
			}

			if (!empty($board))
				$this->_boards[] = (int) $board;

			// The easiest thing is to just get all the boards they can see,
			// but since we've specified the top of tree we ignore some of them
			addChildBoards($this->_boards);

			if (empty($this->_boards))
				fatal_lang_error('error_no_boards_selected');

			$this->_query_parameters['boards'] = $this->_boards;
			$context['querystring_board_limits'] = ';boards=' . implode(',', $this->_boards) . ';start=%d';
		}
		elseif (!empty($board))
		{
			$this->_query_parameters['boards'] = array($board);
			$context['querystring_board_limits'] = ';board=' . $board . '.%1$d';
		}
		elseif (!empty($_REQUEST['boards']))
		{
			$selected_boards = array_map('intval', explode(',', $_REQUEST['boards']));

			$this->_boards = accessibleBoards($selected_boards);

			if (empty($this->_boards))
				fatal_lang_error('error_no_boards_selected');

			$this->_query_parameters['boards'] = $this->_boards;
			$context['querystring_board_limits'] = ';boards=' . implode(',', $this->_boards) . ';start=%1$d';
		}
		elseif (!empty($_REQUEST['c']))
		{
			$categories = array_map('intval', explode(',', $_REQUEST['c']));

			$this->_boards = array_keys(boardsPosts(array(), $categories, isset($_REQUEST['action']) && $_REQUEST['action'] != 'unreadreplies'));

			if (empty($this->_boards))
				fatal_lang_error('error_no_boards_selected');

			$this->_query_parameters['boards'] = $this->_boards;
			$context['querystring_board_limits'] = ';c=' . $_REQUEST['c'] . ';start=%1$d';
		}
		else
		{
			$see_board = isset($_REQUEST['action']) && $_REQUEST['action'] == 'unreadreplies' ? 'query_see_board' : 'query_wanna_see_board';

			// Don't bother to show deleted posts!
			$this->_boards = wantedBoards($see_board);

			if (empty($this->_boards))
				fatal_lang_error('error_no_boards_selected');

			$this->_query_parameters['boards'] = $this->_boards;
			$context['querystring_board_limits'] = ';start=%1$d';
			$context['no_board_limits'] = true;
		}
	}

	/**
	 * Set up the array for the sorting dropdown.
	 */
	private function _sorting_conditions()
	{
		global $context, $txt, $scripturl, $settings;

		$sort_methods = array(
			'subject' => 'ms.subject',
			'starter' => 'IFNULL(mems.real_name, ms.poster_name)',
			'replies' => 't.num_replies',
			'views' => 't.num_views',
			'first_post' => 't.id_topic',
			'last_post' => 't.id_last_msg'
		);

		// The default is the most logical: newest first.
		if (!isset($_REQUEST['sort']) || !isset($sort_methods[$_REQUEST['sort']]))
		{
			$context['sort_by'] = 'last_post';
			$this->_sort_query = 't.id_last_msg';
			$this->_ascending = isset($_REQUEST['asc']);

			$context['querystring_sort_limits'] = $this->_ascending ? ';asc' : '';
		}
		// But, for other methods the default sort is ascending.
		else
		{
			$context['sort_by'] = $_REQUEST['sort'];
			$this->_sort_query = $sort_methods[$_REQUEST['sort']];
			$this->_ascending = !isset($_REQUEST['desc']);

			$context['querystring_sort_limits'] = ';sort=' . $context['sort_by'] . ($this->_ascending ? '' : ';desc');
		}

		$context['sort_direction'] = $this->_ascending ? 'up' : 'down';
		$context['sort_title'] = $this->_ascending ? $txt['sort_desc'] : $txt['sort_asc'];

		// Trick
		$txt['starter'] = $txt['started_by'];

		foreach ($sort_methods as $key => $val)
			$context['topics_headers'][$key] = array(
				'url' => $scripturl . '?action=unread' . ($context['showing_all_topics'] ? ';all' : '') . sprintf($context['querystring_board_limits'], $_REQUEST['start']) . ';sort=subject' . ($context['sort_by'] == 'subject' && $context['sort_direction'] == 'up' ? ';desc' : ''),
				'sort_dir_img' => $context['sort_by'] == $key ? '<img class="sort" src="' . $settings['images_url'] . '/sort_' . $context['sort_direction'] . '.png" alt="" title="' . $context['sort_title'] .'" />' : '',
			);
	}

	/**
	 * Some common things done at the end of each action.
	 */
	private function _exiting_unread()
	{
		global $scripturl, $user_info, $context, $settings, $modSettings;

		$topic_ids = array_keys($context['topics']);

		if ($this->_is_topics && !empty($modSettings['enableParticipation']) && !empty($topic_ids))
		{
			require_once(SUBSDIR . '/MessageIndex.subs.php');
			$topics_participated_in = topicsParticipation($user_info['id'], $topic_ids);

			foreach ($topics_participated_in as $topic)
			{
				if (empty($context['topics'][$topic['id_topic']]['is_posted_in']))
				{
					$context['topics'][$topic['id_topic']]['is_posted_in'] = true;
					$context['topics'][$topic['id_topic']]['class'] = 'my_' . $context['topics'][$topic['id_topic']]['class'];
				}
			}
		}

		// Make sure the starting place makes sense and construct the page index.
		$context['page_index'] = constructPageIndex($scripturl . '?action=' . $_REQUEST['action'] . ($context['showing_all_topics'] ? ';all' : '') . $context['querystring_board_limits'] . $context['querystring_sort_limits'], $_REQUEST['start'], $this->_num_topics, $context['topics_per_page'], true);
		$context['current_page'] = (int) $_REQUEST['start'] / $context['topics_per_page'];

		$context['links'] += array(
			'prev' => $_REQUEST['start'] >= $context['topics_per_page'] ? $scripturl . '?action=' . $_REQUEST['action'] . ($context['showing_all_topics'] ? ';all' : '') . sprintf($context['querystring_board_limits'], $_REQUEST['start'] - $context['topics_per_page']) . $context['querystring_sort_limits'] : '',
			'next' => $_REQUEST['start'] + $context['topics_per_page'] < $this->_num_topics ? $scripturl . '?action=' . $_REQUEST['action'] . ($context['showing_all_topics'] ? ';all' : '') . sprintf($context['querystring_board_limits'], $_REQUEST['start'] + $context['topics_per_page']) . $context['querystring_sort_limits'] : '',
		);
		$context['page_info'] = array(
			'current_page' => $_REQUEST['start'] / $context['topics_per_page'] + 1,
			'num_pages' => floor(($this->_num_topics - 1) / $context['topics_per_page']) + 1
		);

		$context['querystring_board_limits'] = sprintf($context['querystring_board_limits'], $_REQUEST['start']);
		$context['topics_to_mark'] = implode('-', $topic_ids);

		if ($settings['show_mark_read'])
		{
			// Build the recent button array.
			if ($this->_is_topics)
			{
				$context['recent_buttons'] = array(
					'markread' => array('text' => !empty($context['no_board_limits']) ? 'mark_as_read' : 'mark_read_short', 'image' => 'markread.png', 'lang' => true, 'custom' => 'onclick="return markunreadButton(this);"', 'url' => $scripturl . '?action=markasread;sa=' . (!empty($context['no_board_limits']) ? 'all' : 'board' . $context['querystring_board_limits']) . ';' . $context['session_var'] . '=' . $context['session_id']),
				);

				if ($context['showCheckboxes'])
					$context['recent_buttons']['markselectread'] = array(
						'text' => 'quick_mod_markread',
						'image' => 'markselectedread.png',
						'lang' => true,
						'url' => 'javascript:document.quickModForm.submit();',
					);

				if (!empty($context['topics']) && !$context['showing_all_topics'])
					$context['recent_buttons']['readall'] = array('text' => 'unread_topics_all', 'image' => 'markreadall.png', 'lang' => true, 'url' => $scripturl . '?action=unread;all' . $context['querystring_board_limits'], 'active' => true);
			}
			elseif (!$this->_is_topics && isset($context['topics_to_mark']))
			{
				$context['recent_buttons'] = array(
					'markread' => array('text' => 'mark_as_read', 'image' => 'markread.png', 'lang' => true, 'url' => $scripturl . '?action=markasread;sa=unreadreplies;topics=' . $context['topics_to_mark'] . ';' . $context['session_var'] . '=' . $context['session_id']),
				);

				if ($context['showCheckboxes'])
					$context['recent_buttons']['markselectread'] = array(
						'text' => 'quick_mod_markread',
						'image' => 'markselectedread.png',
						'lang' => true,
						'url' => 'javascript:document.quickModForm.submit();',
					);
			}

			// Allow mods to add additional buttons here
			call_integration_hook('integrate_recent_buttons');
		}

		$context['querystring_board_limits'] = 'action=' . ($_REQUEST['action'] == 'unread' ? 'unread' : 'unreadreplies') . (!empty($context['showing_all_topics']) ? ';all' : '') . $context['querystring_board_limits'];

		// Allow helpdesks and bug trackers and what not to add their own unread data (just add a template_layer to show custom stuff in the template!)
		call_integration_hook('integrate_unread_list');
	}
}