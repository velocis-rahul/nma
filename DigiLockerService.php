<?php

namespace App\Services;

use App\Models\Form1ApplicationNo;
use Illuminate\Http\Request;

class DigiLockerService extends BaseFormService
{

    public function formOne(Request $request)
    {
        $query = $this->baseQuery();

        $results = [];
    }

    private function baseQuery()
    {
        return Form1ApplicationNo::query()
            ->where('is_deleted', 0);
    }
}
