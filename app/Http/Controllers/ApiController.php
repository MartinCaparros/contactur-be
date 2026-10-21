<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiProblemException;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as LaravelValidator;

abstract class ApiController extends Controller
{
    /**
     * @param  array<string, mixed>  $rules
     * @param  array<int, string>  $allowedFields
     * @return array<string, mixed>
     */
    protected function validatePayload(
        Request $request,
        array $rules,
        array $allowedFields,
        bool $requireAtLeastOne = false,
    ): array {
        $input = $request->all();
        $validator = Validator::make($input, $rules);
        $validator->after(function (LaravelValidator $validator) use ($input, $allowedFields, $requireAtLeastOne): void {
            foreach (array_diff(array_keys($input), $allowedFields) as $field) {
                $validator->errors()->add($field, __('El campo no está permitido.'));
            }

            if ($requireAtLeastOne && $input === []) {
                $validator->errors()->add('body', __('Debe enviar al menos un campo.'));
            }
        });

        return $validator->validate();
    }

    protected function authenticatedUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new ApiProblemException(401, 'unauthorized', __('No autorizado.'));
        }

        return $user;
    }
}
