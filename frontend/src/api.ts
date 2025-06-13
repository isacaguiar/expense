import axios from 'axios';

const api = axios.create({
  baseURL: 'http://localhost:8080', // ou a URL da sua API backend
});

export default api;
