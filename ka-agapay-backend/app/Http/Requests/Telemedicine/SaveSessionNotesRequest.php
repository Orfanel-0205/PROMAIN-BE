<?php
// app/Http/Requests/Telemedicine/SaveSessionNotesRequest.php

namespace App\Http\Requests\Telemedicine;

use Illuminate\Foundation\Http\FormRequest;

class SaveSessionNotesRequest extends FormRequest
{
    /*
     * This has to agree with the route, and it did not.
     *
     * routes/api.php lets doctor, mho and super_admin reach saveNotes;
     * this list left doctor out. A doctor therefore passed the route's
     * role check and was refused here instead, with a bare 'This action
     * is unauthorized' and nothing to say which of the two checks had
     * objected.
     *
     * Nobody has hit it yet only because the RHU has no doctor account
     * today. The first one they create would have been unable to write a
     * consultation note.
     */
    public function authorize(): bool
    {
        return $this->user()->hasAnyRole(['doctor', 'mho', 'super_admin']);
    }

    public function rules(): array
    {
        return [
            'subjective'               => ['nullable', 'string'],
            'objective'                => ['nullable', 'string'],
            'assessment'               => ['nullable', 'string'],
            'plan'                     => ['nullable', 'string'],
            'primary_diagnosis_code'   => ['nullable', 'string', 'max:20'],
            'primary_diagnosis_label'  => ['nullable', 'string', 'max:255'],
            'medications'              => ['nullable', 'array'],
            'medications.*.name'       => ['required', 'string', 'max:100'],
            'medications.*.dosage'     => ['nullable', 'string', 'max:100'],
            'medications.*.frequency'  => ['nullable', 'string', 'max:100'],
            'medications.*.duration'   => ['nullable', 'string', 'max:100'],
            'finalize'                 => ['sometimes', 'boolean'],

            // telemedicine_session_notes.transcript has existed since the
            // table was created and every row in it is null, because the
            // dictated conversation was never in the accepted fields and
            // validated() drops whatever it does not name.
            'transcript'               => ['nullable', 'string'],
        ];
    }
}