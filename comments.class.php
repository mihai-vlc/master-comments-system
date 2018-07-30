<?php


/**
* This will generate a comments system for you in no time...
* It includes an installed build in so all you have to do is upload the class file run a few commands
* 		and you are ready to go :)
* The intention is to make this a stand alone class so we are using ``mysqli`` to handle the mysql interactions
* 	therefor you will need to pass your mysql details also
* @author  Mihai Ionut Vilcu (ionutvmi@gmail.com)
* July-2013
*/
class Comments_System
{
	/**
	 * Default settings
	 * @var array
	 */
	var $settings = array(
			'comments_table' => '_comments', // the name of the table in which the comments will be hold
			'banned_table' => '_banned', // the name of the table in which the comments will be hold
			'auto_install' => true, // if the class is not already installed it will attempt to install it
			'public' => true, // if true unregistered users are allowed to post a comment
			'optional_email' => false, // if true users don't need to enter a valid email
			'isAdmin' => false, // if true some extra options are displyed delete
			'adminStyle' => array( // special formating to admin messages
				'username' => 'color: #0000ff; font-weidth: bold;', 
				'box' => 'background-color: #FFFCDD'
			),
			'user_details' => array( // if public is false we use this user details to the added message
				'name' => 'user',
				'email' => 'not_reply@gmail.com', 
				),
			'sort' => 'ORDER BY `id` DESC', // the sort, this is pasted as is so make sure it is parsed
		);
	var $link; // it will hold the mysql connection
	var $error = false; // it will hold an error message if any
	var $success = false; // it will hold an success message if any
	var $tmp = false; // it will carry some temporary data
	var	$ignore = array('comm_edit', 'comm_del', 'comm_reply', 'comm_ban', 'comm_unban'); // keep the url clean
	var $checked_ips = array(); // will hold the ips checked if banned or not

	/**
	 * setup the mysql connction and some settings
	 * @param array $db_details contains the database details
	 * @param array  $settings   settings to overwrite the default ones
	 */
	function __construct($db_details , $settings = array())  {
		if(session_id() == '')
			session_start();

		// we first manage the mysql connection
		$this->link = @mysqli_connect($db_details['db_host'], $db_details['db_user'], $db_details['db_pass']);

		if (!$this->link) die('Connect Error (' . mysqli_connect_errno() . ') '.mysqli_connect_error());

		mysqli_select_db($this->link, $db_details['db_name']) or die(mysqli_error($this->link));

		// we add the new settings if any
		$this->settings = array_merge($this->settings, $settings);

		$this->settings['comments_table'] = str_replace("`", "``", $this->settings['comments_table']);

		// auto install
		if($this->settings['auto_install'] && !@mysqli_num_rows(mysqli_query($this->link, "SELECT `id` FROM `".$this->settings['comments_table']."`")))
			$this->install();


		// edit comment
		if(isset($_POST['comm_edit']) && ($comm = mysqli_query($this->link, "SELECT * FROM `".$this->settings['comments_table']."` 
				WHERE `id`='".(int)$_POST['comm_edit']."'")))
		{
			// if the comment exists and the user has the rights to edit it
			if(mysqli_num_rows($comm) && $this->hasRights(mysqli_fetch_object($comm))) {
				$this->grabComment($_SESSION['comm_pageid'], (int)$_POST['comm_edit']);
				$this->tmp = "edited";
			}
		}


		// delete comment
		if(isset($_POST['comm_del']))
			if($this->delComm($_POST['comm_del']))
				$this->success = "Comment deleted !";

		// bann ip
		if( isset($_GET['comm_ban']) && $this->banIP($_GET['comm_ban']) ) // we banned the ip
			$this->success = "IP banned !";
		
		// UnBann ip
		if( isset($_GET['comm_unban']) && $this->unBanIP($_GET['comm_unban']) )
			$this->success = "IP UnBanned !";

		return true;
	}



	function grabComment($pageid, $update_id = false) {
		if(session_id() == '')
			session_start();

		$_SESSION['comm_pageid'] = $pageid;

		// we make sure it's a valid post
		if(isset($_POST['comm_submit']) && isset($_SESSION['comm_token']) && ($_POST['comm_token'] === $_SESSION['comm_token']) &&
			($this->tmp != 'edited')) { // we make sure we don't handle the data again if it was an edit

			$name = isset($_POST['comm_name']) ? $_POST['comm_name'] : '';
			$email = isset($_POST['comm_email']) ? $_POST['comm_email'] : '';
			
			$min = 2;

			if( !$this->settings['public'] ) { // if it's not public we use the provided details
				// if it's not public and it has $_POST[name] something is wrong
				if(isset($_POST['comm_name'])){
					$this->error = "Something is wrong !";
					return false;
				}

				$min = 0;
				$name = $this->settings['user_details']['name'];
				$email = $this->settings['user_details']['email'];

			}

			if($this->isBanned($this->ip())) {
				$this->error = "You are banned !";
				return false;
			}

			if(!isset($name[$min])){
				$this->error = "Invalid name !";
				return false;
			}
			$message = $_POST['comm_msg'];
			
			if(!isset($message[2])) {
				$this->error = "Invalid message !";
				return false;
			}


			// we check in case the email is not valid
			if(!$this->settings['optional_email']) 
				if(!$this->isValidMail($email)) {
					$this->error = "Invalid email !";
					return false;
				}


			// we check if it's an update or a new message 
			if($update_id) {
				if( $this->settings['public'] )
					$upd_fields = ",`name` = '".mysqli_real_escape_string($this->link, $name)."',
									`email` = '".mysqli_real_escape_string($this->link, $email)."'";
				else			
					$upd_fields = '';


				if(mysqli_query($this->link, "UPDATE `".$this->settings['comments_table']."` SET 
					`message` = '".mysqli_real_escape_string($this->link, $message)."'
					$upd_fields
					WHERE `id` = '".(int)$update_id."'")) {

						$this->success = "Comment edited successfully !";
						return true;
				}
			}


			// we check if this is a valid reply
			if(isset($_POST['comm_reply']) && 
				mysqli_num_rows(mysqli_query($this->link, "SELECT `id` FROM `".$this->settings['comments_table']."` 
					WHERE `id`= '".(int)$_POST['comm_reply']."' AND `parent`  = '0'")))					
					$reply = ",`parent` = '".(int)$_POST['comm_reply']."'";
				else
					$reply = ",`parent` = '0'";



			if(mysqli_query($this->link, "INSERT INTO `".$this->settings['comments_table']."` SET 
				`name` = '".mysqli_real_escape_string($this->link, $name)."',
				`message` = '".mysqli_real_escape_string($this->link, $message)."',
				`time` = '".time()."',
				`ip` = '".mysqli_real_escape_string($this->link, $this->ip())."',
				`email` = '".mysqli_real_escape_string($this->link, $email)."',
				`browser` = '".mysqli_real_escape_string($this->link, $_SERVER['HTTP_USER_AGENT'])."',
				`pageid` = '".mysqli_real_escape_string($this->link, $pageid)."',
				`isadmin` = '".(int)$this->settings['isAdmin']."'
				$reply
				")){
				$_SESSION['comm_last_id'] = mysqli_insert_id($this->link);
				$this->success = "Comment posted successfully !";
				return true;
			} else {
				$this->error = "Some error camed up !";
				return false;
			}

		} else if(isset($_POST['comm_submit']) && isset($_SESSION['comm_token']) && ($this->tmp != 'edited')) {
			unset($_SESSION['comm_token']);
			$this->error = "Invalid request !";
		}
	}

	/**
	 * Lists the comments for the inserted page id
	 * @param  integer $pageid  
	 * @param  integer $perpage 
	 * @return string           the generated html code
	 */
	function generateComments($pageid = 0, $perpage = 10) {

		if(session_id() == '')
			session_start();

		$_SESSION['comm_pageid'] = $pageid;

		$html ="<ul class='media-list'>";

		
		$comments = $this->getComments($pageid, $perpage);
		

		// we generate the output of the comments
		if($comments)
		foreach ($comments as $comment) {
			if(!($name = $this->getUsername($comment->name)))
				$name = $comment->name;

			// show reply link or form
			if(isset($_GET['comm_reply']) && ($comment->id == $_GET['comm_reply']))
				$show_reply = $this->generateForm("?".$this->queryString('', $this->ignore), 1);
			else
				$show_reply = "<a href='?".$this->queryString('', $this->ignore)."&comm_reply=$comment->id#$comment->id'>Reply</a>";

			// show normal username or with adminStyles
			$style ="";
			if($comment->isadmin) {
				$show_name = "<span style='".$this->settings['adminStyle']['username']."'>".$this->html($name)."</span>";
				$style = $this->settings['adminStyle']['box'];
			} else
				$show_name = $this->html($name);

			// show extra info only to admin
			$show_extra ="";
			if($this->settings['isAdmin']) {
				$browser = explode(" ", $comment->browser);
				$show_extra = "($comment->email | ".$browser[0]." | $comment->ip)";
			}
			$is_del = (isset($_GET['comm_del']) && ($_GET['comm_del'] === $comment->id) && $this->hasRights($comment))
						? " background-color: #FFDE89; border: 1px solid red;" : null;

			$html .= "
			<li class='media' id='$comment->id' style='".$style.$is_del."'>
				<a class='pull-left' href='http://gravatar.com'>
				<img class='media-object' src='http://gravatar.com/avatar/".md5($comment->email)."'>
				</a>
				<div class='media-body'>";

			if(isset($_GET['comm_edit']) && ($_GET['comm_edit'] === $comment->id) && $this->hasRights($comment))
				// we generate the form in edit mode with precompleted data
				$html .= $this->generateForm('', 2, $comment);
			else
				$html .= "<h4 class='media-heading'>
						$show_name $show_extra
						<small class='muted'>".$this->tsince($comment->time)."</small>
						".$this->admin_options($comment)."
					</h4>
					<p>".nl2br($this->html($comment->message))."</p>";

			if($is_del)
				$html .= $this->gennerateConfirm('', 'comm_del', $comment->id);
			else
				$html .= $show_reply;

				$html .= $this->generateReplies($comment->id)."
				</div>
			</li>";
		}

		$html .= "</ul>".$this->generatePages($pageid, $perpage);

		return $html;
	}


function generateReplies($comm_id, $limit = 3) {
	$html = "";
	$comments = $this->getReplies($comm_id, $limit);
	// we generate the output of the comments
	if($comments)
	foreach ($comments as $comment) {
		if(!($name = $this->getUsername($comment->name)))
			$name = $comment->name;	

		// show normal username or with adminStyles
		$style ="";
		if($comment->isadmin) {
			$show_name = "<span style='".$this->settings['adminStyle']['username']."'>".$this->html($name)."</span>";
			$style = $this->settings['adminStyle']['box'];
		} else
			$show_name = $this->html($name);

			// show extra info only to admin
			$show_extra ="";
			if($this->settings['isAdmin']) {
				$browser = explode(" ", $comment->browser);
				$show_extra = "($comment->email | ".$browser[0]." | $comment->ip)";
			}
			$is_del = (isset($_GET['comm_del']) && ($_GET['comm_del'] === $comment->id) && $this->hasRights($comment))
						? " background-color: #FFDE89; border: 1px solid red;" : null;

			$html .= "
			<div class='media' id='$comment->id' style='". $style. $is_del ."'>
				<a class='pull-left' href='http://gravatar.com'>
				<img class='media-object' src='http://gravatar.com/avatar/".md5($comment->email)."'>
				</a>	
				<div class='media-body'>";
			

			if(isset($_GET['comm_edit']) && ($_GET['comm_edit'] === $comment->id) && $this->hasRights($comment))
				// we generate the form in edit mode with precompleted data
				$html .= $this->generateForm('', 2, $comment);
			else
				$html .= "<h4 class='media-heading'>
						$show_name $show_extra
						<small class='muted'>".$this->tsince($comment->time)." replied </small>
						".$this->admin_options($comment)."
					</h4>
					<p>".nl2br($this->html($comment->message))."</p>";
			
			if($is_del)
				$html .= $this->gennerateConfirm('', 'comm_del', $comment->id);

			$html .= "</div></div>";

	}

	return $html;
}

	function generateForm($location =  '', $type = 0, $comment = false) {
		$this->setToken();
		if($location == '')
			$location = "?".$this->queryString('', $this->ignore);


		if(!$comment)
			$comment = (object)array("name"=>"","email"=>"","message"=>"");

		if($type == 1)
			$title = "<input type='hidden' name='comm_reply' value='".(int)$_GET['comm_reply']."'>Post a reply";
		else if($type == 2)
			$title = "<input type='hidden' name='comm_edit' value='".(int)$_GET['comm_edit']."'>Edit comment";
		else
			$title = "Post a comment";

		$show_name_email = '';
		
		if( $this->settings['public'] ) {
			$show_name_email = "<div class='control-group'>
			  <label class='control-label' for='comm_name'>Name</label>
			  <div class='controls'>
				<input id='comm_name' name='comm_name' type='text' class='input-xlarge' value='$comment->name'>
				
			  </div>
			</div>

			<div class='control-group'>
			  <label class='control-label' for='comm_email'>Email</label>
			  <div class='controls'>
				<input id='comm_email' name='comm_email' type='text' class='input-xlarge' value='$comment->email'>
			  	<p>
			  	".($this->settings['optional_email'] ? "(optional, it will not be public.)" : "")."
			  	</p>
			  </div>
			</div>";
		}


		$html = "
	<form class='form-horizontal' action='$location#comm_status' method='post'>
		<fieldset>
		<legend>$title</legend>

		$show_name_email

		<div class='control-group'>
		  <label class='control-label' for='comm_msg'>Message</label>
		  <div class='controls'>					 
			<textarea class='input-xlarge' id='comm_msg' name='comm_msg'>$comment->message</textarea>
		  </div>
		</div>

		<input type='hidden' name='comm_token' value='".$_SESSION['comm_token']."'>

		<div class='control-group'>
		  <div class='controls'>
			<input type='submit' id='comm_submit' name='comm_submit' class='btn btn-primary' value='Post'>
			".($type ? "<input type='submit' id='comm_cancel' value='Cancel' class='btn'>" : "")."
		  </div>
		</div>

		</fieldset>
	</form>";

		return $html;
	}

	/**
	 * it will create the table to hold the comments
	 * @return boolean true if the install succeeds
	 */
	function install() {

		$sql = "CREATE TABLE IF NOT EXISTS `".$this->settings['comments_table']."` (
		  `id` int(11) NOT NULL AUTO_INCREMENT,
		  `name` varchar(255) NOT NULL,
		  `message` text NOT NULL,
		  `time` int(11) NOT NULL,
		  `ip` varchar(255) NOT NULL,
		  `email` varchar(255) NOT NULL,
		  `browser` varchar(255) NOT NULL,
		  `pageid` int(11) NOT NULL,
		  `parent` int(11) NOT NULL,
		  `isadmin` int(11) NOT NULL,
		  PRIMARY KEY (`id`)
		);";

		$sql2 = "CREATE TABLE IF NOT EXISTS `_banned` (
		  `ip` varchar(255) NOT NULL,
		  UNIQUE KEY `ip` (`ip`)
		);";

		if(mysqli_query($this->link, $sql) && mysqli_query($this->link, $sql2))
			return true;

		return false;

	}
	/**
	 * gets the comments from the db
	 * @param  integer $pageid the id for the specific page
	 * @param  integer $perpage number of comments perpage
	 * @return array		  the comments
	 */
	function getComments($pageid = 0, $perpage = 10) {
		$comments = array();

		$sql = "SELECT * FROM `".$this->settings['comments_table']."` WHERE `parent` = 0 ";

		if($pageid)
			$sql .= "AND `pageid` = '".mysqli_real_escape_string($this->link, $pageid)."'";

		// some sorting options
		$sql .= " ".$this->settings['sort']." "; // this is pasted as is


		// grab the page number
		$page_number = !isset($_GET['comm_page']) || ((int)$_GET['comm_page'] <= 0) ? 1 : (int)$_GET['comm_page']; 

		$total_results = mysqli_num_rows(mysqli_query($this->link, $sql));

		if($page_number > ceil($total_results/$perpage))
			$page_number = ceil($total_results/$perpage);

		$start = ($page_number - 1) * $perpage;

		$sql .= "LIMIT $start, $perpage";

		if($result = mysqli_query($this->link, $sql))
			while($row = mysqli_fetch_object($result))
				$comments[] = $row;
		else
			return false;

		return $comments;
	}
	/**
	 * gets the replies to a certain comment
	 * @param  integer $comm_id the id for the specific comment
	 * @param  integer $limit max number of comments to be displayed as reply
	 * @return array		  the comments
	 */
	function getReplies($comm_id = 0, $limit = 3) {
		$comments = array();

		$sql = "SELECT * FROM `".$this->settings['comments_table']."` 
			WHERE `parent` = '".mysqli_real_escape_string($this->link, $comm_id)."'";

		// limitation
		$sql .= "LIMIT 0, $limit";

		if($result = mysqli_query($this->link, $sql))
			while($row = mysqli_fetch_object($result))
				$comments[] = $row;
		else
			return false;

		return $comments;
	}

	/**
	 * it parses the text for output
	 * @param  string $text the text to be parsed
	 * @return string	   paesed text
	 */
	function html($text) {
		return htmlentities($text, ENT_QUOTES);
	}

	/**
	 * while developing this class a small problem camed up,
	 * 	what if i have a user system already and i want to store the userid instread of he's username(in case he changes it) ?
	 *  for this matter i made this function which by default will return false
	 *  which means that we will consider that the `name` column is a string not an integer(userid)
	 *  BUT if you store the userid in the name column you have to make sure that this function
	 *  will return the username coresponding to that id, i included an example
	 * 
	 */
	function getUsername($userid) {
		return false;
		// in case you decide to store the userid use this
		// $user = mysqli_fetch_object(mysql_query($this->link, "SELECT * FROM `users` WHERE 'userid' = '".(int)$userid."'"));
		// return $user->username;
	}

	/**
	 * Time elapes since a times
	 * @param  int $time The past time
	 * @return string	   time elapssed
	 * credits: http://stackoverflow.com/a/2916189/1579481
	 */
	function tsince($time, $end_msg = 'ago') {
 
		$time = abs(time() - $time); // to get the time since that moment

		if($time == 0)
			return "Just now";

		$tokens = array (
			31536000 => 'year',
			2592000 => 'month',
			604800 => 'week',
			86400 => 'day',
			3600 => 'hour',
			60 => 'minute',
			1 => 'second'
		);

		foreach ($tokens as $unit => $text) {
			if ($time < $unit) continue;
			$numberOfUnits = floor($time / $unit);
			return $numberOfUnits.' '.$text.(($numberOfUnits>1)?'s':'').' '. $end_msg;
		}
 
	}

	function isValidMail($mail) {
	 
		if(!filter_var($mail, FILTER_VALIDATE_EMAIL))
			return FALSE;


		list($username, $maildomain) = explode("@", $mail);
		if(checkdnsrr($maildomain, "MX"))
			return TRUE;

		return FALSE;
	}

	// Returns the real IP address of the user
	function ip()
	{
		// No IP found (will be overwritten by for
		// if any IP is found behind a firewall)
		$ip = FALSE;
		
		// If HTTP_CLIENT_IP is set, then give it priority
		if (!empty($_SERVER["HTTP_CLIENT_IP"])) {
			$ip = $_SERVER["HTTP_CLIENT_IP"];
		}
		
		// User is behind a proxy and check that we discard RFC1918 IP addresses
		// if they are behind a proxy then only figure out which IP belongs to the
		// user.  Might not need any more hackin if there is a squid reverse proxy
		// infront of apache.
		if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {

			// Put the IP's into an array which we shall work with shortly.
			$ips = explode (", ", $_SERVER['HTTP_X_FORWARDED_FOR']);
			if ($ip) { array_unshift($ips, $ip); $ip = FALSE; }

			for ($i = 0; $i < count($ips); $i++) {
				// Skip RFC 1918 IP's 10.0.0.0/8, 172.16.0.0/12 and
				// 192.168.0.0/16
				if (!preg_match('/^(?:10|172\.(?:1[6-9]|2\d|3[01])|192\.168)\./', $ips[$i])) {
					if (version_compare(phpversion(), "5.0.0", ">=")) {
						if (ip2long($ips[$i]) != false) {
							$ip = $ips[$i];
							break;
						}
					} else {
						if (ip2long($ips[$i]) != -1) {
							$ip = $ips[$i];
							break;
						}
					}
				}
			}
		}

		// Return with the found IP or the remote address
		return ($ip ? $ip : $_SERVER['REMOTE_ADDR']);
	}
	/**
	 * sets a random token stored in the session used to validate the form submit
	 */
	function setToken() {
		if(session_id() == '')
			session_start();

		$_SESSION['comm_token'] = md5(time().rand());
	}
	/**
	 * it will return the query string as hidden fields or as url
	 * @param  string $type   type of output
	 * @param  array  $ignore ignored elements
	 * @return string         
	 */
	function queryString($type = '', $ignore = array()) {

		$result = '';

		foreach($_GET as $k => $v) {
			if((is_array($ignore) && in_array($k, $ignore)) 
				|| (is_string($ignore) && preg_match($ignore, $k)))
				continue;

			if($type == 'hidden') {
				$result .= "<input type='hidden' name='".urlencode($k)."' value='".urlencode($v)."'>";
			} else {
				$result[] = urlencode($k)."=".urlencode($v);
			}
		}

		if(is_array($result))
			return implode("&", $result);

		return $result;


	}


	/**
	 * generates a confirmation form
	 * @return string the html code of the form
	 */
	function gennerateConfirm($location = '', $info_name = 'comm_del', $info_value = 0, $submit = "I&#39;m sure, Delete") {

		if($location == '')
			$location = "?".$this->queryString('', $this->ignore);

	return "<form class='form-horizontal' action='$location' method='post'>
		<div class='control-group'>
		  <div class='controls'>
		  ".($info_name ? "<input type='hidden' name='$info_name' value='$info_value'>" : "")."
			<input type='submit' id='comm_submit' name='comm_confirm' class='btn btn-primary' value='$submit'>
			<a href='".$_SERVER['HTTP_REFERER']."' class='btn'>Cancel</a>
		  </div>
		</div>
		</form>";
	}
	/**
	 * checks if the current user has the rights to delete/edit a comment
	 * @param  object $comment data related to one comment
	 * @return boolean [description]
	 */
	function hasRights($comment) {
		if(session_id() == '')
			session_start();

		if($this->settings['isAdmin'] || (isset($_SESSION['comm_last_id']) && $_SESSION['comm_last_id'] == $comment->id))
			return true;
		return false;
	}
	/**
	 * returns the html code of the options available
	 * @param  object $comment data related to one comment
	 * @return string          the html code to display the options
	 */
	function admin_options($comment) {
		// if is admin or the person who posted the message
		if($this->hasRights($comment))
			return "<a href='?".$this->queryString('', $this->ignore)."&comm_edit=$comment->id#$comment->id'>Edit</a> 
				| <a href='?".$this->queryString('', $this->ignore)."&comm_del=$comment->id#$comment->id'>Delete</a>".
				($this->settings['isAdmin'] ? //if is admin 
					" | <a href='?".$this->queryString('', $this->ignore)."&comm_".
					($this->isBanned($comment->ip) ? "un" : "")."ban=".urlencode($comment->ip)."'>".
					($this->isBanned($comment->ip) ? "Un" : "")."Ban</a>" : "");
	}


	/**
	 * will generate the html code for page numbers
	 * @param  interger  $total   the total number of elements
	 * @param  interger  $page    current page
	 * @param  integer $perpage number of elements per page
	 * @return string           the generated html code
	 */
	function generatePages($pageid, $perpage = 10){


		$sql = "SELECT `id` FROM `".$this->settings['comments_table']."` WHERE `parent` = 0 ";

		if($pageid)
			$sql .=  " AND `pageid`='".(int)$pageid."'";


		$total = mysqli_num_rows(mysqli_query($this->link, $sql));
		
		$total_pages = ceil($total/$perpage);
		
		$page = !isset($_GET['comm_page']) || ((int)$_GET['comm_page'] <= 0) ? 1 : (int)$_GET['comm_page']; 
		
		$query = "&".$this->queryString('', array('comm_page'));

		$html = "<div class='pagination'><ul>";

		if($page > 4)
			$html .= "<li><a href='?$query'>First</a></li>";

		if($page > 1)
			$html .= "<li><a href='?comm_page=".($page-1)."$query'>Prev</a> </li>";

		for($i = max(1, $page - 3); $i <= min($page + 3, $total_pages); $i++)
			$html .= ($i == $page ? "<li class='active'><a>".$i."</a></li>" : " <li><a href='?comm_page=$i$query'>$i</a></li> ");

		if($page < $total_pages)
			$html .= "<li><a href='?comm_page=".($page+1)."$query'>Next</a></li>";

		if($page < $total_pages-3)
			$html .= "<li><a href='?comm_page=$total_pages$query'> Last </a></li>";

		$html .= "</ul></div>";

		return $html;
	}

	/**
	 * deletes comment based on the comment id, it also checks for the rights of the user.
	 * @param  interger $comment_id the id of the comment to be deleted
	 * @return boolean             true on success
	 */
	function delComm($comment_id) {
		$comm = mysqli_query($this->link, "SELECT `id` FROM `".$this->settings['comments_table']."` WHERE `id` = '".(int)$comment_id."'");
		if(mysqli_num_rows($comm) && $this->hasRights(mysqli_fetch_object($comm))) {
			mysqli_query($this->link, "DELETE FROM `".$this->settings['comments_table']."` 
				WHERE `id` = '".(int)$comment_id."' OR `parent` = '".(int)$comment_id."'");

			return true;
		}

		return false;
	}
	/**
	 * Adds the inserted ip in the banned list
	 * @param  string $ip the ip to be banned
	 */
	function banIP( $ip ) {
		if($this->settings['isAdmin'])
			if(mysqli_query($this->link, "INSERT INTO `".$this->settings['banned_table']."` 
				SET `ip` = '".mysqli_real_escape_string($this->link, $ip)."'"))
				return true;
		return false;
	}

	/**
	 * Deletes an ip from the banned list.
	 * @param  string $ip the ip to be unbanned
	 */
	function unBanIP( $ip ) {
		if($this->settings['isAdmin']) {
			mysqli_query($this->link, "DELETE FROM `".$this->settings['banned_table']."` 
				WHERE `ip` = '".mysqli_real_escape_string($this->link, $ip)."'");
			return true;
		}

		return false;
	}
	/**
	 * checks if an ip is banned
	 * @param  string $ip ip to be checked
	 * @return boolean     true if the ip is banned
	 */
	function isBanned( $ip ) {
		// no need to check the same ip 2 times in a row
		if(count($this->checked_ips) && in_array($ip, array_keys($this->checked_ips)))
			return $this->checked_ips[$ip];

		$this->checked_ips[$ip] = $ip;


		if(mysqli_num_rows(mysqli_query($this->link, "SELECT * FROM `".$this->settings['banned_table']."` 
			WHERE `ip` = '".mysqli_real_escape_string($this->link, $ip)."'"))) {
			$this->checked_ips[$ip] = true;
			return true;
		}
		$this->checked_ips[$ip] = false;


		return false;
	}

}
















