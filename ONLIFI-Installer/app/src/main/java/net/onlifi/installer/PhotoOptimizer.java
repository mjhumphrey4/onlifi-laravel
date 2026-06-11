package net.onlifi.installer;

import android.graphics.Bitmap;
import android.graphics.BitmapFactory;

import java.io.File;
import java.io.FileOutputStream;

final class PhotoOptimizer {
    private static final int MAX_EDGE = 1600;
    private static final long MAX_BYTES = 7L * 1024L * 1024L;

    private PhotoOptimizer() {
    }

    static void optimize(File file) throws Exception {
        if (file == null || !file.exists()) {
            return;
        }

        BitmapFactory.Options bounds = new BitmapFactory.Options();
        bounds.inJustDecodeBounds = true;
        BitmapFactory.decodeFile(file.getAbsolutePath(), bounds);
        if (bounds.outWidth <= 0 || bounds.outHeight <= 0) {
            return;
        }

        BitmapFactory.Options options = new BitmapFactory.Options();
        options.inSampleSize = sampleSize(bounds.outWidth, bounds.outHeight);
        Bitmap bitmap = BitmapFactory.decodeFile(file.getAbsolutePath(), options);
        if (bitmap == null) {
            return;
        }

        int quality = file.length() > MAX_BYTES ? 78 : 84;
        try (FileOutputStream outputStream = new FileOutputStream(file, false)) {
            bitmap.compress(Bitmap.CompressFormat.JPEG, quality, outputStream);
        } finally {
            bitmap.recycle();
        }
    }

    private static int sampleSize(int width, int height) {
        int sample = 1;
        while ((width / sample) > MAX_EDGE || (height / sample) > MAX_EDGE) {
            sample *= 2;
        }
        return sample;
    }
}
