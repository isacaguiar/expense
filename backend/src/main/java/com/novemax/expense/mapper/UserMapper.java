package com.novemax.expense.mapper;

import com.novemax.expense.model.User;
import com.novemax.expense.repository.UserRepository;
import java.util.List;
import java.util.UUID;
import java.util.stream.Collectors;
import org.springframework.stereotype.Component;

@Component
public class UserMapper {

    private final UserRepository userRepository;

    public UserMapper(UserRepository userRepository) {
        this.userRepository = userRepository;
    }

    public User toUser(UUID id) {
        return userRepository.findById(id)
            .orElseThrow(() -> new IllegalArgumentException("Usuário não encontrado para ID: " + id));
    }

    public UUID toUUID(User user) {
        return user.getId();
    }

    public List<User> toUserList(List<UUID> ids) {
        return ids.stream().map(this::toUser).collect(Collectors.toList());
    }

    public List<UUID> toUUIDList(List<User> users) {
        return users.stream().map(User::getId).collect(Collectors.toList());
    }
}
