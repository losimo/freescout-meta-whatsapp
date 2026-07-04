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
            'phone_number'    => 'required|regex:/^\+[1-9]\d{6,14}$/',
            'phone_number_id' => 'required|string|max:50|unique:meta_whatsapp_accounts,phone_number_id' . ($id ? ',' . $id : ''),
            'waba_id'         => 'required|string|max:50',
            'verify_token'    => 'required|string|size:64',
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
