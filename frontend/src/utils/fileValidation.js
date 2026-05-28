const allowed = ['image/png', 'image/jpeg', 'image/jpg', 'image/webp'];
const maxSize = 10 * 1024 * 1024;

export const validateFile = (file) => {
  if (!file) return 'Please select a file.';
  if (!allowed.includes(file.type)) return 'Only PNG, JPG, and WebP files are supported.';
  if (file.size > maxSize) return 'File must be 10MB or smaller.';
  return null;
};
