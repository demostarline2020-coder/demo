import axios from 'axios';

const API = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || 'http://localhost:5000',
  timeout: 120000,
});

export const vectorizeImage = async (file) => {
  const formData = new FormData();
  formData.append('image', file);
  const { data } = await API.post('/api/vectorize', formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  });
  return data;
};
