<?php

/**
 * Admin directory redirect
 * This file ensures that accessing /admin/ redirects to the admin dashboard
 */

// Redirect to index.php in the same directory
header('Location: index.php');
exit();
