import { Routes, Route } from 'react-router-dom';
import { BusinessHoursPage } from '@/pages/BusinessHours/BusinessHoursPage';
import { BusinessHoursCreatePage } from '@/pages/BusinessHours/BusinessHoursCreatePage';
import { BusinessHoursEditPage } from '@/pages/BusinessHours/BusinessHoursEditPage';

function App() {
    return (
        <div className="min-h-screen bg-gray-50">
            <Routes>
                <Route path="/business-hours" element={<BusinessHoursPage />} />
                <Route path="/business-hours/create" element={<BusinessHoursCreatePage />} />
                <Route path="/business-hours/:id/edit" element={<BusinessHoursEditPage />} />
            </Routes>
        </div>
    );
}

export default App;