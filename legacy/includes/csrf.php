<?php



function nb_csrf_token()
{
    if (empty($_SESSION["csrf_token"])) {
        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
    }

    return $_SESSION["csrf_token"];
}

function nb_csrf_field()
{
    echo '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(nb_csrf_token())
        . '">';
}

function nb_csrf_check()
{
    $token = $_POST["csrf_token"] ?? "";

    if (
        empty($_SESSION["csrf_token"]) ||
        !hash_equals($_SESSION["csrf_token"], $token)
    ) {

        http_response_code(403);
        die("Security check failed. Please go back and try again.");
    }
}
