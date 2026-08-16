<?php

namespace Modules\MetaWhatsApp\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WhatsAppAccountRequest extends FormRequest
{
    public function authorize()
    {
        return auth()->user() && auth()->user()->isAdmin();
    }

    public function rules()
    {
        $id = $this->route('id');

        $rules = [
            'name'            => 'required|string|max:100',
            'conversation_subject_template' => 'nullable|string|max:190',
            'phone_number'    => 'required|regex:/^\+[1-9]\d{6,14}$/',
            'phone_number_id' => 'required|string|max:50|unique:meta_whatsapp_accounts,phone_number_id' . ($id ? ',' . $id : ''),
            'waba_id'         => 'required|string|max:50',
            'verify_token'    => 'required|string|size:64',
            'template_name'              => 'nullable|string|max:512',
            'template_lang'              => 'nullable|string|max:15|regex:/^[a-z]{2}(_[A-Z]{2})?$/',
            'template_threshold_minutes' => 'nullable|integer|min:1|max:1440',
            // Fins a 5 plantilles (issue #2, punts 2-4): id+language obligatoris
            // junts per fila plena, la resta opcional. Files buides es
            // descarten al controlador, no cal 'required' aquí.
            'templates'                  => 'nullable|array|max:5',
            'templates.*.id'             => 'nullable|string|max:512',
            'templates.*.language'       => 'nullable|string|max:15|regex:/^[a-z]{2}(_[A-Z]{2})?$/',
            'templates.*.display_name'   => 'nullable|string|max:190',
            'templates.*.recovery_text'  => 'nullable|string|max:1000',
        ];

        if ($id) {
            // Edit: credencials opcionals (en blanc = mantenir); bústia immutable.
            $rules['access_token'] = 'nullable|string|min:20';
            $rules['app_secret']   = 'nullable|string|min:16';
        } else {
            $rules['access_token'] = 'required|string|min:20';
            $rules['app_secret']   = 'required|string|min:16';
            $rules['mailbox_mode'] = 'required|in:new,existing';
            $rules['mailbox_id']   = 'required_if:mailbox_mode,existing|nullable|exists:mailboxes,id';
            $rules['mailbox_name'] = 'required_if:mailbox_mode,new|nullable|string|max:100';
        }

        return $rules;
    }

    public function messages()
    {
        return [
            'phone_number.regex' => __('metawhatsapp::metawhatsapp.phone_number_format'),
        ];
    }
}
