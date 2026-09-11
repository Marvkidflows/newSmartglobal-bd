@extends('layouts.app')

@section('title', 'Register - Step 2 of 4')

@section('content')
<div class="registration-wrapper">
    <div class="registration-container">
        <!-- Progress Bar -->
        <div class="registration-progress">
            <div class="progress-step completed">
                <div class="step-circle">✓</div>
                <span class="step-label">Basic Info</span>
            </div>
            <div class="progress-line completed"></div>
            <div class="progress-step active">
                <div class="step-circle">2</div>
                <span class="step-label">Verification</span>
            </div>
            <div class="progress-line"></div>
            <div class="progress-step">
                <div class="step-circle">3</div>
                <span class="step-label">Profile</span>
            </div>
            <div class="progress-line"></div>
            <div class="progress-step">
                <div class="step-circle">4</div>
                <span class="step-label">Security</span>
            </div>
        </div>

        <!-- Registration Card -->
        <div class="registration-card">
            <div class="registration-header">
                <h2 class="registration-title">Identity Verification</h2>
                <p class="registration-subtitle">Step 2 of 4: KYC Verification</p>
                <div class="info-box">
                    <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                        <circle cx="10" cy="10" r="9" stroke="#2563EB" stroke-width="2"/>
                        <path d="M10 6V11" stroke="#2563EB" stroke-width="2" stroke-linecap="round"/>
                        <circle cx="10" cy="14" r="1" fill="#2563EB"/>
                    </svg>
                    <span>Your documents are encrypted and securely stored. This process helps us comply with financial regulations.</span>
                </div>
            </div>

            @if ($errors->any())
                <div class="alert alert-error">
                    <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                        <circle cx="10" cy="10" r="9" stroke="currentColor" stroke-width="2"/>
                        <path d="M10 6V11" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <circle cx="10" cy="14" r="1" fill="currentColor"/>
                    </svg>
                    <div>
                        <strong>Please fix the following errors:</strong>
                        <ul style="margin: 0.5rem 0 0 1.5rem;">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endif

            <form method="POST" action="{{ route('register.stage2.submit') }}" class="registration-form" enctype="multipart/form-data">
                @csrf

                <!-- ID Type Selection -->
                <div class="form-group">
                    <label class="form-label">
                        ID Document Type <span class="required">*</span>
                    </label>
                    <div class="radio-group">
                        <label class="radio-label">
                            <input type="radio" name="id_type" value="passport" {{ old('id_type') == 'passport' ? 'checked' : '' }} required>
                            <span class="radio-text">
                                <strong>International Passport</strong>
                                <small>Valid government-issued passport</small>
                            </span>
                        </label>
                        <label class="radio-label">
                            <input type="radio" name="id_type" value="national_id" {{ old('id_type', 'national_id') == 'national_id' ? 'checked' : '' }}>
                            <span class="radio-text">
                                <strong>National ID Card</strong>
                                <small>Government-issued national identity card</small>
                            </span>
                        </label>
                        <label class="radio-label">
                            <input type="radio" name="id_type" value="drivers_license" {{ old('id_type') == 'drivers_license' ? 'checked' : '' }}>
                            <span class="radio-text">
                                <strong>Driver's License</strong>
                                <small>Valid driver's license with photo</small>
                            </span>
                        </label>
                    </div>
                    @error('id_type')
                        <span class="error-message">{{ $message }}</span>
                    @enderror
                </div>

                <!-- ID Number -->
                <div class="form-group">
                    <label class="form-label">
                        ID Number <span class="required">*</span>
                    </label>
                    <input 
                        type="text"
                        name="id_number"
                        value="{{ old('id_number') }}"
                        class="form-input @error('id_number') error @enderror"
                        placeholder="Enter your ID number"
                        required
                    />
                    @error('id_number')
                        <span class="error-message">{{ $message }}</span>
                    @enderror
                </div>

                <!-- Upload ID Document -->
                <div class="form-group">
                    <label class="form-label">
                        Upload ID Document <span class="required">*</span>
                    </label>
                    <div class="file-upload-wrapper">
                        <input 
                            type="file" 
                            name="id_document" 
                            id="id_document" 
                            class="file-input"
                            accept=".jpg,.jpeg,.png,.pdf"
                            required
                            onchange="previewFile(this, 'id-preview')"
                        >
                        <label for="id_document" class="file-upload-label">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
                                <path d="M21 15V19C21 19.5304 20.7893 20.0391 20.4142 20.4142C20.0391 20.7893 19.5304 21 19 21H5C4.46957 21 3.96086 20.7893 3.58579 20.4142C3.21071 20.0391 3 19.5304 3 19V15" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                <path d="M17 8L12 3L7 8M12 3V15" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                            <span class="file-upload-text">Click to upload or drag and drop</span>
                            <span class="file-upload-hint">JPG, PNG or PDF (max 5MB)</span>
                        </label>
                        <div id="id-preview" class="file-preview"></div>
                    </div>
                    @error('id_document')
                        <span class="error-message">{{ $message }}</span>
                    @enderror
                </div>

                <!-- Upload Selfie -->
                <div class="form-group">
                    <label class="form-label">
                        Upload Selfie <span class="required">*</span>
                    </label>
                    <div class="file-upload-wrapper">
                        <input 
                            type="file" 
                            name="selfie" 
                            id="selfie" 
                            class="file-input"
                            accept=".jpg,.jpeg,.png"
                            required
                            onchange="previewFile(this, 'selfie-preview')"
                        >
                        <label for="selfie" class="file-upload-label">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
                                <circle cx="12" cy="8" r="4" stroke="currentColor" stroke-width="2"/>
                                <path d="M6 21V19C6 16.79 7.79 15 10 15H14C16.21 15 18 16.79 18 19V21" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                            <span class="file-upload-text">Click to upload your selfie</span>
                            <span class="file-upload-hint">JPG or PNG (max 5MB) - Hold your ID next to your face</span>
                        </label>
                        <div id="selfie-preview" class="file-preview"></div>
                    </div>
                    @error('selfie')
                        <span class="error-message">{{ $message }}</span>
                    @enderror
                </div>

                <!-- Date of Birth -->
                <div class="form-group">
                    <label class="form-label">
                        Date of Birth <span class="required">*</span>
                    </label>
                    <input 
                        type="date"
                        name="date_of_birth"
                        value="{{ old('date_of_birth') }}"
                        class="form-input @error('date_of_birth') error @enderror"
                        max="{{ now()->subYears(18)->format('Y-m-d') }}"
                        required
                    />
                    <small class="form-hint">You must be at least 18 years old</small>
                    @error('date_of_birth')
                        <span class="error-message">{{ $message }}</span>
                    @enderror
                </div>

                <!-- Residential Address -->
                <div class="form-group">
                    <label class="form-label">
                        Residential Address <span class="required">*</span>
                    </label>
                    <textarea 
                        name="residential_address"
                        class="form-input @error('residential_address') error @enderror"
                        placeholder="Street address, apartment/unit number"
                        rows="3"
                        required
                    >{{ old('residential_address') }}</textarea>
                    @error('residential_address')
                        <span class="error-message">{{ $message }}</span>
                    @enderror
                </div>

                <!-- City and State -->
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">City <span class="required">*</span></label>
                        <input 
                            type="text"
                            name="city"
                            value="{{ old('city') }}"
                            class="form-input @error('city') error @enderror"
                            placeholder="City"
                            required
                        />
                        @error('city')
                            <span class="error-message">{{ $message }}</span>
                        @enderror
                    </div>
                    <div class="form-group">
                        <label class="form-label">State/Province <span class="required">*</span></label>
                        <input 
                            type="text"
                            name="state"
                            value="{{ old('state') }}"
                            class="form-input @error('state') error @enderror"
                            placeholder="State"
                            required
                        />
                        @error('state')
                            <span class="error-message">{{ $message }}</span>
                        @enderror
                    </div>
                </div>

                <!-- Postal Code -->
                <div class="form-group">
                    <label class="form-label">
                        Postal/ZIP Code <span class="required">*</span>
                    </label>
                    <input 
                        type="text"
                        name="postal_code"
                        value="{{ old('postal_code') }}"
                        class="form-input @error('postal_code') error @enderror"
                        placeholder="Postal code"
                        required
                    />
                    @error('postal_code')
                        <span class="error-message">{{ $message }}</span>
                    @enderror
                </div>

                <!-- Submit Button -->
                <button type="submit" class="btn btn-primary btn-full">
                    Continue to Profile
                    <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                        <path d="M7 14L12 10L7 6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </button>
            </form>
        </div>
    </div>
</div>

<script>
function previewFile(input, previewId) {
    const preview = document.getElementById(previewId);
    const file = input.files[0];
    
    if (file) {
        const fileSize = (file.size / 1024 / 1024).toFixed(2); // Size in MB
        const fileName = file.name;
        const fileType = file.type;
        
        let previewHTML = '';
        
        if (fileType.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = function(e) {
                previewHTML = `
                    <div class="file-preview-item">
                        <img src="${e.target.result}" alt="Preview" style="max-width: 200px; border-radius: 8px;">
                        <div class="file-info">
                            <strong>${fileName}</strong>
                            <small>${fileSize} MB</small>
                        </div>
                        <button type="button" class="file-remove" onclick="removeFile('${input.id}', '${previewId}')">
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                                <path d="M12 4L4 12M4 4L12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                        </button>
                    </div>
                `;
                preview.innerHTML = previewHTML;
                preview.style.display = 'block';
            };
            reader.readAsDataURL(file);
        } else {
            previewHTML = `
                <div class="file-preview-item">
                    <svg width="40" height="40" viewBox="0 0 40 40" fill="none">
                        <path d="M24 4H10C8.9 4 8 4.9 8 6V34C8 35.1 8.9 36 10 36H30C31.1 36 32 35.1 32 34V12L24 4Z" stroke="#2563EB" stroke-width="2"/>
                        <path d="M24 4V12H32" stroke="#2563EB" stroke-width="2"/>
                    </svg>
                    <div class="file-info">
                        <strong>${fileName}</strong>
                        <small>${fileSize} MB</small>
                    </div>
                    <button type="button" class="file-remove" onclick="removeFile('${input.id}', '${previewId}')">
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                            <path d="M12 4L4 12M4 4L12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                    </button>
                </div>
            `;
            preview.innerHTML = previewHTML;
            preview.style.display = 'block';
        }
    }
}

function removeFile(inputId, previewId) {
    document.getElementById(inputId).value = '';
    document.getElementById(previewId).innerHTML = '';
    document.getElementById(previewId).style.display = 'none';
}
</script>
@endsection