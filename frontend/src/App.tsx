import React from 'react';
import { BrowserRouter as Router, Routes, Route } from 'react-router-dom';
import LoginPage from './pages/LoginPage';
import Dashboard from './pages/Dashboard';
import GroupForm from './pages/GroupForm';
import GroupMembersForm from './pages/GroupMembersForm';
import ExpenseManager from './pages/ExpenseManager';
import ExpenseForm from './pages/ExpenseForm';
import ExpensesEntry from './pages/ExpensesEntry';
import GroupSummary from './pages/GroupSummary';
import SummaryEntry from './pages/SummaryEntry';
import RequireAuth from './components/RequireAuth';
import GroupShellLayout from './layouts/GroupShellLayout';
import SimpleShellLayout from './layouts/SimpleShellLayout';

const App = () => {
  return (
    <Routes>
      <Route path="/" element={<LoginPage />} />

      {/* Rotas privadas */}
      <Route element={<RequireAuth />}>
        {/* Com grupo selecionado: sidebar/navegação de grupo (GroupShellLayout) */}
        <Route element={<GroupShellLayout />}>
          <Route path="/groups/:id/summary" element={<GroupSummary />} />
          <Route path="/groups/:id/expenses" element={<ExpenseManager />} />
          <Route path="/groups/:id/expenses/new" element={<ExpenseForm />} />
          <Route path="/groups/:id/members" element={<GroupMembersForm />} />
          <Route path="/groups/:id/edit" element={<GroupForm />} />
        </Route>

        {/* Sem grupo selecionado: cabeçalho simples (SimpleShellLayout) */}
        <Route element={<SimpleShellLayout />}>
          <Route path="/dashboard" element={<Dashboard />} />
          <Route path="/groups/new" element={<GroupForm />} />
          <Route path="/expenses" element={<ExpensesEntry />} />
          <Route path="/summary" element={<SummaryEntry />} />
        </Route>
      </Route>

      {/* rota “catch-all” opcional */}
      <Route path="*" element={<h2>404: Página não encontrada</h2>} />
    </Routes>
  );
};

export default App;
