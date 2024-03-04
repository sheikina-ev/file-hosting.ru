<?php

namespace App\Http\Controllers;

use App\Http\Requests\FileStoreRequest;
use App\Models\File;
use App\Models\Right;
use Illuminate\Http\Request;
use Illuminate\Validation\Validator;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FileController extends Controller
{
    // Метод сохранения файлов на сервере
    public function store(Request $request)
    {
        // Проверка наличия файлов
        if ($request->hasFile('files')) {
            $uploadedFiles = [];

            foreach ($request->file('files') as $file) {
                // Валидация файла уже выполнена в классе запроса
                $uploadedFile = $this->saveFile($file);

                $uploadedFiles[] = [
                    'success' => true,
                    'code' => 200,
                    'message' => 'Success',
                    'name' => $uploadedFile->originalName,
                    'url' => url("files/{$uploadedFile->id}"),
                    'file_id' => $uploadedFile->file_id
                ];
            }

            return response()->json($uploadedFiles);
        }

        // Если файлы отсутствуют
        return response()->json(['message' => 'No files to upload'], 400);
    }

    // Вспомогательный метод для сохранения файла
    private function saveFile($file)
    {
        $originalName = $file->getClientOriginalName();
        $extension = $file->getClientOriginalExtension();
        $fileName = $this->generateUniqueFileName($originalName, $extension);

        // Сохранение файла на сервере
        $file->storeAs('uploads', $fileName);

        // Создание записи в базе данных
        $uploadedFile = new File();
        $uploadedFile->name = pathinfo($originalName, PATHINFO_FILENAME);
        $uploadedFile->extension = $extension;
        $uploadedFile->path = $fileName;
        $uploadedFile->file_id = Str::random(10);
        // Добавление связи с пользователем, предполагая, что информация о пользователе доступна в запросе
        $uploadedFile->user_id = auth()->id();
        $uploadedFile->save();

        $uploadedFile->originalName = $originalName; // Добавляем оригинальное имя в объект для удобства

        return $uploadedFile;
    }

    // Генерация уникального имени файла
    private function generateUniqueFileName($originalName, $extension)
    {
        $fileName = Str::slug(pathinfo($originalName, PATHINFO_FILENAME));
        $i = 1;

        while (Storage::exists("uploads/{$fileName}.{$extension}")) {
            $fileName = Str::slug(pathinfo($originalName, PATHINFO_FILENAME)) . " ({$i})";
            $i++;
        }

        return $fileName . '.' . $extension;
    }

    public function download($file_id)
    {
        // Проверяем, существует ли файл с данным идентификатором
        $file = File::where('file_id', $file_id)->first();

        if (!$file) {
            // Если файл не найден, возвращаем ответ 404 Not Found
            return response()->json(['message' => 'Not found'], 404);
        }

        // Проверяем, имеет ли пользователь доступ к файлу
        if (!auth()->check() || auth()->user()->id !== $file->user_id) {
            // Если доступ запрещен, возвращаем ответ 403 Forbidden
            return response()->json(['message' => 'Forbidden for you'], 403);
        }

        // Получаем путь к файлу на сервере
        $filePath = storage_path('app/uploads/' . $file->path);

        // Проверяем существует ли файл
        if (!Storage::exists('uploads/' . $file->path)) {
            // Если файл не найден на сервере, возвращаем ответ 404 Not Found
            return response()->json(['message' => 'Not found'], 404);
        }

        // Возвращаем файл для скачивания
        return response()->download($filePath, $file->name . '.' . $file->extension);
    }

    public function edit(Request $request, $file_id)
    {
        // Проверяем, существует ли файл с переданным идентификатором
        $file = File::where('file_id', $file_id)->first();

        // Если файл не найден, возвращаем ответ 404 Not Found
        if (!$file) {
            return response()->json(['message' => 'Not found'], 404);
        }

        // Проверяем, авторизован ли пользователь и имеет ли доступ к файлу
        if (!auth()->check() || auth()->user()->id !== $file->user_id) {
            // Если доступ запрещен, возвращаем ответ 403 Forbidden
            return response()->json(['message' => 'Forbidden for you'], 403);
        }


        // Обновляем имя файла
        $file->name = $request->input('name');
        $file->save();

        // Возвращаем успешный ответ
        return response()->json([
            'success' => true,
            'code' => 200,
            'message' => 'Renamed'
        ]);
    }

    public function destroy($file_id)
    {
        // Проверяем, существует ли файл с переданным идентификатором
        $file = File::where('file_id', $file_id)->first();

        // Если файл не найден, возвращаем ответ 404 Not Found
        if (!$file) {
            return response()->json(['message' => 'Not found'], 404);
        }

        // Проверяем, авторизован ли пользователь и имеет ли доступ к файлу
        if (!auth()->check() || auth()->user()->id !== $file->user_id) {
            // Если доступ запрещен, возвращаем ответ 403 Forbidden
            return response()->json(['message' => 'Forbidden for you'], 403);
        }

        // Удаляем файл из хранилища
        Storage::delete('uploads/' . $file->path);

        // Удаляем запись о файле из базы данных
        $file->delete();

        // Возвращаем успешный ответ
        return response()->json([
            'success' => true,
            'code' => 200,
            'message' => 'File deleted'
        ]);
    }

    public function owned(Request $request)
    {
        // Получаем идентификатор текущего авторизованного пользователя
        $userId = $request->user()->id;

        // Получаем файлы, загруженные текущим пользователем
        $files = File::where('user_id', $userId)->with('rights.user')->get();

        // Формируем ответ
        $response = [];
        foreach ($files as $file) {
            $accesses = [];
            foreach ($file->rights as $right) {
                $accesses[] = [
                    'fullname' => $right->user->full_name,
                    'email' => $right->user->email,
                    'type' => 'co-author',
                ];
            }

            $response[] = [
                'file_id' => $file->file_id,
                'name' => $file->name,
                'code' => 200,
                'url' => url("files/{$file->file_id}"),
                'accesses' => $accesses
            ];
        }

        // Возвращаем сформированный ответ
        return response()->json($response, 200);
    }

    public function allowed()
    {
        // Получаем идентификатор текущего авторизованного пользователя
        $userId = auth()->id();

        // Получаем файлы, к которым пользователь имеет доступ через таблицу прав доступа
        $filesWithAccess = Right::where('user_id', $userId)->with('file')->get()->pluck('file');

        // Формируем ответ, исключая файлы, загруженные самим пользователем
        $response = [];
        foreach ($filesWithAccess as $file) {
            if ($file->user_id != $userId) {
                $response[] = [
                    'file_id' => $file->file_id,
                    'code' => 200,
                    'name' => $file->name,
                    'url' => url("files/{$file->file_id}")
                ];
            }
        }

        // Возвращаем сформированный ответ
        return response()->json($response, 200);
    }


}
