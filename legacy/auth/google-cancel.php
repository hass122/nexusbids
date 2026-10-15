<?php

session_start();

unset($_SESSION["google_pending"]);

header("Location: login.php");
exit();
