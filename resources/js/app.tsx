import { Routes, Route } from 'react-router-dom';
import { LoginPage } from '@/pages/LoginPage';

function App() {
    return (
        <div className="min-h-screen bg-gray-50">
            <Routes>
                <Route path="/" element={<LoginPage />} />
                <Route path="/login" element={<LoginPage />} />
            </Routes>
        </div>
    );
}

export default App;
