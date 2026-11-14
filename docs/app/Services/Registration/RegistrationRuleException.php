<?php
// LOCATION: app/Services/Registration/RegistrationRuleException.php
// A business rule refused the action. Controllers turn this into a 422 with the message; it is never an unexpected error.

namespace App\Services\Registration;

use RuntimeException;

class RegistrationRuleException extends RuntimeException
{
}
