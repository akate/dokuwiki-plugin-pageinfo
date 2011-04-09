<?php
/**
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author	Kate Arzamastseva  
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();

if (!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');

require_once(DOKU_PLUGIN.'action.php');

class action_plugin_pageinfo extends DokuWiki_Action_Plugin{

	function getInfo() {
		return array(
		'author' => 'Kate Arzamastseva',
		'email'  => 'pshns@ukr.net',
		'date'   => @file_get_contents(DOKU_PLUGIN.'pageinfo/VERSION'),
		'name'   => 'PageInfo',
		'desc'   => 'Page information',
		'url'    => '',
		);
	}

	/**
     * register the eventhandlers
     */
	function register(& $controller) {
		$controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'handle_act_preprocess');
		$controller->register_hook('TPL_ACT_UNKNOWN', 'BEFORE', $this, 'handle_act_unknown');
	}

	function handle_act_preprocess(& $event, $param) {
		if ($event->data == 'pageinfo') {
			$event->stopPropagation();
			$event->preventDefault();
		}
	}

	function handle_act_unknown(& $event, $param) {
		if ($event->data == 'pageinfo') {
			$this->_html_info();
			$event->preventDefault();
		}
	}

	function _html_info() {
		print p_cached_output($this->localfn('pageinfo'));
		$this->_html_changes();
		$this->_html_links('links');
		$this->_html_links('backlinks');
	}

	function _html_changes() {
		$recents = $this->_getChanges();
		print p_cached_output($this->localfn('recent'));
		
		$form = new Doku_Form(array('id' => 'dw__recent', 'method' => 'GET'));
		$form->addElement(form_makeOpenTag('ul'));

		foreach($recents as $recent){
			$date = dformat($recent['date']);
			if ($recent['type']===DOKU_CHANGE_TYPE_MINOR_EDIT)
			$form->addElement(form_makeOpenTag('li', array('class' => 'minor')));
			else
			$form->addElement(form_makeOpenTag('li'));

			$form->addElement(form_makeOpenTag('div', array('class' => 'li')));

			$form->addElement(form_makeOpenTag('span', array('class' => 'date')));
			$form->addElement($date);
			$form->addElement(form_makeCloseTag('span'));

			$form->addElement(form_makeOpenTag('a', array('class' => 'diff_link', 'href' => wl($recent['id'],"do=diff", false, '&'))));
			$form->addElement(form_makeTag('img', array(
			'src'   => DOKU_BASE.'lib/images/diff.png',
			'width' => 15,
			'height'=> 11,
			'title' => $lang['diff'],
			'alt'   => $lang['diff']
			)));
			$form->addElement(form_makeCloseTag('a'));

			$form->addElement(form_makeOpenTag('span', array('class' => 'sum')));
			$form->addElement(' &ndash; '.htmlspecialchars($recent['sum']));
			$form->addElement(form_makeCloseTag('span'));

			$form->addElement(form_makeOpenTag('span', array('class' => 'user')));
			if($recent['user']){
				$form->addElement(editorinfo($recent['user']));
				if(auth_ismanager()){
					$form->addElement(' ('.$recent['ip'].')');
				}
			}else{
				$form->addElement($recent['ip']);
			}
			$form->addElement(form_makeCloseTag('span'));

			$form->addElement(form_makeCloseTag('div'));
			$form->addElement(form_makeCloseTag('li'));
		}
		$form->addElement(form_makeCloseTag('ul'));
		html_form('recent', $form);
	}

	function _getChanges() {
		global $conf;
		global $ID;
		$id = $ID;
		$changes = array();
		$file = metaFN($id, '.changes');
		$lines = @file($file);

		for($i = count($lines)-1; $i >= 0; $i--){
			$change = $this->_handleChangelogLine($lines[$i], $id);
			if($change !== false){
				$changes[] = $change;
			}
		}

		return $changes;
	}

	function _handleChangelogLine($line, $id) {
		// split the line into parts
		$change = parseChangelogLine($line);
		if($change === false) return false;

		// filter user
		//if(!empty($user) && (empty($change['user']) ||
		//                    !in_array($change['user'], $user))) return false;

		// check ACL
		$change['perms'] = auth_quickaclcheck($change['id']);
		if ($change['perms'] < AUTH_READ) return false;

		return $change;
	}

	function _html_links($type){
		global $ID;
		global $conf;
		global $lang;

		switch($type){
			case 'links':
				print p_cached_output($this->localfn('links'));
				$data = $this->_getLinks($ID);
				break;
			case 'backlinks':
				print p_cached_output($this->localfn('backlinks'));
				$data = ft_backlinks($ID);
				break;
		}

		if(!empty($data)) {
			print '<ul class="idx">';
			foreach($data as $blink){
				print '<li><div class="li">';
				print html_wikilink(':'.$blink,useHeading('navigation')?null:$blink);
				print '</div></li>';
			}
			print '</ul>';
		} else {
			print '<div class="level1"><p>' . $lang['nothingfound'] . '</p></div>';
		}
	}

	function _getLinks($id){
		$result = array();

		$result = $this->_lookupExtetnalLinks('relation_references', $id);

		/*if(!count($result)) return $result;

		// check ACL permissions
		foreach(array_keys($result) as $idx){
		if(auth_quickaclcheck($result[$idx]) < AUTH_READ){
		unset($result[$idx]);
		}
		}*/

		sort($result);
		return $result;
	}

	function _lookupExtetnalLinks($key, $value) {
		$val = $value;
		$metaname = idx_cleanName($key);
		$result = array();

		$words = $this->_getIndex($metaname, '_w');
		$page_idx = $this->_getIndex('page', '');

		if (($i = array_search($val, $words)) !== false) $w_idx = $i;
		if (($i = array_search($val, $page_idx)) !== false) $idx = $i;

		$lines = $this->_getIndex($metaname, '_p');
		$line = $lines[$idx];
		$parts = explode(':', $line);
		foreach ($parts as $tuple) {
			if ($tuple === '') continue;
			$key = $words[$tuple];
			if (!$key) continue;
			$result[$key] = $tuple;
		}
		$pages = array_keys($result);
		return $pages;
	}

	function _getIndex($idx, $suffix) {
		global $conf;
		$fn = $conf['indexdir'].'/'.$idx.$suffix.'.idx';
		if (!@file_exists($fn)) return array();
		return file($fn, FILE_IGNORE_NEW_LINES);
	}

}