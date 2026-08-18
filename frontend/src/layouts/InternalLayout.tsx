import React from 'react';
import { Outlet } from 'react-router-dom';
import Navbar from '../components/Navbar';

const InternalLayout: React.FC = () => {
  return (
    <>
      <Navbar />
      <main style={{ padding: '16px' }}>
        <Outlet />
      </main>
    </>
  );
};

export default InternalLayout;
