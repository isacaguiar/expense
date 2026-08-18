import React from 'react';
import { BrowserRouter as Router, Routes, Route } from 'react-router-dom';
import LoginPage from './pages/LoginPage';
import Dashboard from './pages/Dashboard';
import GroupForm from './pages/GroupForm';
import InternalLayout from './layouts/InternalLayout';
import GroupMembersForm from './pages/GroupMembersForm';
import GroupList from './pages/GroupList';
import ExpenseManager from './pages/ExpenseManager';
import ExpensesEntry from './pages/ExpensesEntry';
import RequireAuth from './components/RequireAuth';

const App = () => {
  return (
    <Routes>
      <Route path="/" element={<LoginPage />} />


      {/* Rotas privadas com layout */}
      <Route element={<RequireAuth />}>
        <Route element={<InternalLayout />}>
          <Route path="/dashboard" element={<Dashboard />} />

          {/* Rota de grupo */}
          <Route path="/groups" element={<GroupList />} />
          <Route path="/groups/new" element={<GroupForm />} />
          <Route path="/groups/:id/edit" element={<GroupForm />} />
          <Route path="/groups/:id/members" element={<GroupMembersForm />} />

          <Route path="/groups/:id/expenses" element={<ExpenseManager />} />
          <Route path="/expenses" element={<ExpensesEntry />} />
        </Route>
      </Route>

      {/* rota “catch-all” opcional */}
      <Route path="*" element={<h2>404: Página não encontrada</h2>} />
    </Routes>
  );
};

export default App;
