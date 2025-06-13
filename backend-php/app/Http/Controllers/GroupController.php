<?php

namespace App\Http\Controllers;

use App\Models\Group;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GroupController extends Controller
{
    public function index()
    {
        
        Log::info('User authenticated:', ['user' => auth()->user()]);
        //return Group::all();
        return Group::where('deleted', 0)->get();
    }

    public function store(Request $request)
    {
        Log::info('User authenticated:', ['user' => auth()->user()]);
    
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:255',
        ]);

        $data['create_date'] = now();
        $data['deleted'] = false;

        $group = Group::create($data);

        Log::info('Grupo criado!');

        return response()->json($group, 201);
    }

    public function show($id)
    {
        return Group::findOrFail($id);
    }

    public function update(Request $request, $id)
    {
        $group = Group::findOrFail($id);
        $group->update($request->only(['name', 'description']));

        return response()->json($group);
    }

    public function destroy($id)
    {
        $group = Group::findOrFail($id);
        $group->update(['deleted' => true]);

        return response()->json(['message' => 'Grupo marcado como deletado.']);
    }
}
