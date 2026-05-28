const allowedMimeTypes = new Set(['image/png', 'image/jpeg', 'image/jpg', 'image/webp']);
const allowedExtensions = new Set(['png', 'jpg', 'jpeg', 'webp']);
const maxSize = 10 * 1024 * 1024;

const getExtension = (name = '') => name.toLowerCase().split('.').pop();

export const validateFile = (file) => {
  if (!file) return 'Please select a file.';

  const extension = getExtension(file.name);
  const isMimeValid = file.type ? allowedMimeTypes.has(file.type.toLowerCase()) : false;
  const isExtensionValid = allowedExtensions.has(extension);

  if (!isMimeValid && !isExtensionValid) {
    return 'Only PNG, JPG, JPEG, and WebP files are supported.';
  }

  if (file.size > maxSize) return 'File must be 10MB or smaller.';
  return null;
};
