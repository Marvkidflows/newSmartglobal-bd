<?php
namespace Tests\Feature\Remediation;

use App\Support\ProofStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProofStoreTest extends TestCase
{
    public function test_default_stores_on_the_public_disk_and_returns_a_relative_path(): void
    {
        Storage::fake('public');
        config(['uploads.deposit_proof_disk' => 'public']);
        $p = ProofStore::save(UploadedFile::fake()->image('p.jpg'));
        $this->assertStringStartsWith('deposit-screenshots/', $p);
        Storage::disk('public')->assertExists($p);
    }

    public function test_remote_disk_returns_the_disks_url_not_a_relative_path(): void
    {
        Storage::fake('public');
        Storage::fake('remote_proof');
        config(['uploads.deposit_proof_disk' => 'remote_proof']);
        $p = ProofStore::save(UploadedFile::fake()->image('p.png'));
        // A faked disk reports a URL (a real Cloudinary disk reports https://...), never the bare relative path.
        $this->assertStringStartsWith('/storage/', $p);
        $this->assertTrue(Storage::disk('remote_proof')->exists(ltrim(substr($p, strlen('/storage/')), '/')));
    }

    public function test_unknown_remote_disk_falls_back_to_local_instead_of_failing(): void
    {
        Storage::fake('public');
        config(['uploads.deposit_proof_disk' => 'does_not_exist']);
        $p = ProofStore::save(UploadedFile::fake()->image('p.jpg'));
        $this->assertStringStartsWith('deposit-screenshots/', $p);
        Storage::disk('public')->assertExists($p);
    }
}
