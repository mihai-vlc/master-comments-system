Master Comments System
=======================

This is a very compact comments system with basic options: 
* Comments can be accepted from all users or only registered users.  
* Admin can Edit/Delete/Ban.  
* Users can Edit/Delete their last posted comment.  
* Admin can view the IP/Browser of the user who posted the comment.  
* Easy integration in any kind of script.
* It's meant to be easy to use for basic usage, very compact and flexible.

How to use
--------------
```php

include 'comments.class.php';


$db_details = array(
	'db_host' => 'localhost',
	'db_user' => 'root',
	'db_pass' => '',
	'db_name' => 'test'
	);

$page_id = 1;

$comments = new Comments_System($db_details);

$comments->grabComment($page_id);

if($comments->success)
	echo "<div class='alert alert-success' id='comm_status'>".$comments->success."</div>";
else if($comments->error)
	echo "<div class='alert alert-error' id='comm_status'>".$comments->error."</div>";

// a simple form
echo $comments->generateForm();

// we show the posted comments
echo $comments->generateComments($page_id); // we pass the page id
```

You have lots of settings you can tweak
-------------
```php

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
```





ScreenShoots  
-----------------
![screen1](http://puu.sh/3O69V.png)  
--------------
![screen2](http://puu.sh/3O6ot.png)  


Contribute
---------------
Any contribution is gladly welcomed.  
This is still in early stages so if you find bugs you can report bugs [here](https://github.com/ionutvmi/master-comments-system/issues)