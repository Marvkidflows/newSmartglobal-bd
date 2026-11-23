<?php
// LOCATION: config/uploads.php   (NEW FILE)
return [
    // Where investor payment-proof images are stored. "public" = local disk (lost on ephemeral hosts); "cloudinary" = persistent.
    'deposit_proof_disk' => env('DEPOSIT_PROOF_DISK', 'public'),
];
