<?php

namespace App\Http\Controllers\Api\Production;

use App\Http\Controllers\Controller;
use App\Services\Production\ProductionOrderItemImageService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductionOrderItemImageController extends Controller
{
    /**
     * Store a new item image.
     */
    public function store(Request $request, ProductionOrderItemImageService $service)
    {
        try {
            $request->validate([
                'image' => ['sometimes', 'required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:10240'],
            ]);

            $data = $request->only(['production_order_item_id']);

            // Handle file upload and normalize output to webp.
            if ($request->hasFile('image')) {
                $data['image_path'] = $this->storeCompressedWebp($request->file('image'));
            }

            $result = $service->create($data);

            return $this->successResponse($result, 'Production order item image created successfully', 201);
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation failed', 422, $e->errors());
        }
    }


    /**
     * Update an existing item image.
     */
    public function update(Request $request, ProductionOrderItemImageService $service, int $id)
    {
        try {
            $request->validate([
                'image' => ['sometimes', 'required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:10240'],
            ]);

            $data = $request->only(['production_order_item_id', 'image_path']);
            $existingPathToDelete = null;

            if ($request->hasFile('image')) {
                $existing = $service->findById($id);
                $existingPathToDelete = $existing->image_path;
                $data['image_path'] = $this->storeCompressedWebp($request->file('image'));
            }

            $result = $service->update($id, $data);

            if ($existingPathToDelete) {
                Storage::disk('public')->delete($existingPathToDelete);
            }

            return $this->successResponse($result, 'Production order item image updated successfully');
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Production order item image not found', 404);
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation failed', 422, $e->errors());
        }
    }

    /**
     * Delete an item image.
     */
    public function destroy($id, ProductionOrderItemImageService $service)
{
    try {
        $image = $service->findById($id);
        
        // Delete the file from storage/app/public/image
        Storage::disk('public')->delete($image->image_path);
        
        $image->delete();
        
        return $this->successResponse(null, 'Image deleted successfully');
    } catch (ModelNotFoundException $e) {
        return $this->errorResponse('Image not found', 404);
    }
}

    /**
     * Convert uploaded image to webp and store it in storage/app/public/image.
     *
     * @throws ValidationException
     */
    private function storeCompressedWebp(UploadedFile $file): string
    {
        $realPath = $file->getRealPath();
        $imageInfo = $realPath ? getimagesize($realPath) : false;

        if (! $imageInfo || ! isset($imageInfo['mime'])) {
            throw ValidationException::withMessages([
                'image' => ['Uploaded file is not a valid image.'],
            ]);
        }

        $sourceImage = match ($imageInfo['mime']) {
            'image/jpeg' => imagecreatefromjpeg($realPath),
            'image/png' => imagecreatefrompng($realPath),
            'image/webp' => imagecreatefromwebp($realPath),
            default => null,
        };

        if (! $sourceImage) {
            throw ValidationException::withMessages([
                'image' => ['Image format is not supported for compression.'],
            ]);
        }

        // Store to storage/app/public/image/ instead of public/image/
        $directory = storage_path('app/public/image');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $fileName = time() . '_' . Str::random(12) . '.webp';
        $destinationPath = $directory . DIRECTORY_SEPARATOR . $fileName;
        $isSaved = imagewebp($sourceImage, $destinationPath, 80);
        imagedestroy($sourceImage);

        if (! $isSaved) {
            throw ValidationException::withMessages([
                'image' => ['Failed to compress and save image.'],
            ]);
        }

        return 'image/' . $fileName;
    }

}
