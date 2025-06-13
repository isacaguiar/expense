package com.novemax.expense.service;

import com.novemax.expense.model.User;
import com.novemax.expense.repository.UserRepository;
import org.springframework.stereotype.Service;

import java.util.List;
import java.util.UUID;

@Service
public class UserService {

    private final UserRepository repository;

    public UserService(UserRepository repository) {
        this.repository = repository;
    }

    public List<User> listarTodos() {
        return repository.findAll();
    }

    public User buscarPorId(UUID id) {
        return repository.findById(id).orElse(null);
    }

    public User salvar(User user) {
        return repository.save(user);
    }
}