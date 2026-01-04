<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;

class StoreRecordingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = auth()->user();
        return $user->hasRole(UserRole::OWNER) || $user->hasRole(UserRole::PBX_ADMIN);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'name' => 'required|string|max:255',
            'type' => 'required|in:upload,remote',
        ];

        if ($this->input('type') === 'upload') {
            $rules['file'] = [
                'required',
                'file',
                'mimes:mp3,wav',
                'max:' . (5 * 1024), // 5MB in KB
            ];
        } elseif ($this->input('type') === 'remote') {
            $rules['remote_url'] = [
                'required',
                'url',
                'regex:/^https?:\/\/.+/i', // Allow both HTTP and HTTPS
            ];
        }

        return $rules;
    }

    /**
     * Get custom error messages for validation.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Please select a file to upload.',
            'file.mimes' => 'Only MP3 and WAV files are allowed.',
            'file.max' => 'File size must not exceed 5MB.',
            'remote_url.required' => 'Please provide a remote URL.',
            'remote_url.url' => 'Please provide a valid URL.',
            'remote_url.regex' => 'URL must start with http:// or https://.',
        ];
    }
}
