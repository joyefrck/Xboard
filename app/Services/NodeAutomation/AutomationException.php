<?php

namespace App\Services\NodeAutomation;

// Only explicit, credential-free messages cross the admin API or enter logs.
class AutomationException extends \RuntimeException {}
